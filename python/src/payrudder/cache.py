"""Entitlement checks and the catalog kept in a store the seller provides (Redis, or memory), invalidated by webhooks.

>>> cache = PayrudderCache(client, store=RedisStore(redis.Redis()))
>>> cache.entitlement("cus_42", "api_calls")["allowed"]
"""

from __future__ import annotations

import json
import secrets
import threading
import time
from collections import OrderedDict
from collections.abc import Callable, Mapping
from decimal import Decimal, InvalidOperation
from typing import TYPE_CHECKING, Any, Protocol, TypedDict, TypeVar, cast

from . import models as m

if TYPE_CHECKING:
    from ._client import Payrudder

T = TypeVar("T")

_CUSTOMER_EVENTS = {"entitlement.updated", "customer.blocked", "customer.unblocked", "credits.purchased"}
_CATALOG_EVENTS = {"plan.created", "plan.version_published", "campaign.started", "campaign.ended"}


class CacheStore(Protocol):
    """Where ``PayrudderCache`` keeps its entries. ``MemoryStore`` and ``RedisStore`` ship with the SDK."""

    def get(self, key: str) -> str | None: ...
    def set(self, key: str, value: str, ttl_ms: int) -> None: ...
    def delete(self, key: str) -> None: ...


class MemoryStore:
    """A least-recently-used store in the memory of this process (thread-safe). Not shared between instances."""

    def __init__(self, max_entries: int = 10_000, now: Callable[[], float] = time.monotonic) -> None:
        self._entries: OrderedDict[str, tuple[str, float]] = OrderedDict()
        self._max = max_entries
        self._now = now
        self._lock = threading.Lock()

    def get(self, key: str) -> str | None:
        with self._lock:
            hit = self._entries.get(key)
            if hit is None:
                return None
            if hit[1] <= self._now():
                del self._entries[key]
                return None
            self._entries.move_to_end(key)
            return hit[0]

    def set(self, key: str, value: str, ttl_ms: int) -> None:
        with self._lock:
            self._entries[key] = (value, self._now() + ttl_ms / 1000)
            self._entries.move_to_end(key)
            if len(self._entries) > self._max:
                self._entries.popitem(last=False)

    def delete(self, key: str) -> None:
        with self._lock:
            self._entries.pop(key, None)


class RedisStore:
    """Wraps the redis-py client you already have (``redis.Redis``); the SDK gains no dependency."""

    def __init__(self, client: Any) -> None:
        self._client = client

    def get(self, key: str) -> str | None:
        value = self._client.get(key)
        return value.decode() if isinstance(value, bytes) else cast("str | None", value)

    def set(self, key: str, value: str, ttl_ms: int) -> None:
        self._client.set(key, value, px=ttl_ms)

    def delete(self, key: str) -> None:
        self._client.delete(key)


class CatalogPlan(TypedDict):
    plan: m.Plan
    version: m.PlanVersion


class Catalog(TypedDict):
    """Plans with their current prices, active credit packs and running campaigns."""

    plans: list[CatalogPlan]
    packs: list[m.CreditPack]
    campaigns: list[m.Campaign]


class PayrudderCache:
    """Entitlement checks and the catalog answered from a store every instance of your app shares.

    Payrudder stays the source of truth: pass verified webhooks to ``handle_webhook`` and a change invalidates the
    cached answers at once; without it they are refreshed after the TTL. Invalidation is by generation: each customer
    and the catalog have a generation key, entries are stored under the generation they were read at, and
    invalidating writes a new random generation. A store error never fails a call: it reads through to the API and
    goes to ``on_error``.
    """

    def __init__(
        self,
        client: Payrudder,
        *,
        store: CacheStore | None = None,
        prefix: str = "payrudder:",
        entitlement_ttl_ms: int = 60_000,
        catalog_ttl_ms: int = 600_000,
        on_error: Callable[[Exception], None] | None = None,
    ) -> None:
        self._client = client
        self._store: CacheStore = store if store is not None else MemoryStore()
        self._base = f"{prefix}{client.environment}:"
        self._ent_ttl = entitlement_ttl_ms
        self._cat_ttl = catalog_ttl_ms
        self._gen_ttl = 2 * max(entitlement_ttl_ms, catalog_ttl_ms)
        self._on_error = on_error or (lambda _err: None)

    def entitlement(self, customer: str, meter: str) -> m.EntitlementStatus:
        """The customer's status on one meter."""
        return self._cached(
            self._customer_gen_key(customer),
            lambda g: f"ent:{customer}:{g}:{meter}",
            self._ent_ttl,
            lambda: self._client.customers.get_entitlement(customer, meter),
        )

    def entitlements(self, customer: str) -> list[m.EntitlementStatus]:
        """The customer's status on every meter they have an entitlement on."""
        return self._cached(
            self._customer_gen_key(customer),
            lambda g: f"ents:{customer}:{g}",
            self._ent_ttl,
            lambda: self._client.customers.list_entitlements(customer)["items"],
        )

    def catalog(self) -> Catalog:
        """Plans with their current prices, active credit packs and running campaigns."""

        def load() -> Catalog:
            c = self._client
            plans: list[CatalogPlan] = [
                {"plan": p, "version": c.plans.get_plan_version(p["code"], str(p["latest_version"]))}
                for p in c.plans.list_plans()["plans"]
                if p["latest_version"] > 0
            ]
            return {
                "plans": plans,
                "packs": c.credit_packs.list_credit_packs(active=True)["packs"],
                "campaigns": c.campaigns.list_campaigns(status="running")["campaigns"],
            }

        return self._cached(self._base + "gen:catalog", lambda g: f"catalog:{g}", self._cat_ttl, load)

    def record_usage(self, event: m.IncomingEvent) -> m.EventResult:
        """Record one usage event through the API, then count it into the cached entitlement of that customer and
        meter (``used += quantity``, ``allowed`` recomputed for a hard limit), so the next check needs no request."""
        result = self._client.events.ingest([event])[0]
        if result.get("status") != "accepted":
            return result
        customer, meter, quantity = event["customer_external_id"], event["meter_code"], str(event["quantity"])
        ok, gen = self._get(self._customer_gen_key(customer))
        if not ok or not gen:
            return result  # no generation: nothing was cached under it
        one = self._base + f"ent:{customer}:{gen}:{meter}"
        ok, raw = self._get(one)
        if ok and raw:
            status = _parse(raw)
            if isinstance(status, dict) and _count_usage(status, quantity):
                self._set(one, json.dumps(status), self._ent_ttl)
            else:
                self._delete(one)
        every = self._base + f"ents:{customer}:{gen}"
        ok, raw = self._get(every)
        if ok and raw:
            items = _parse(raw)
            match = None
            if isinstance(items, list):
                match = [s for s in items if isinstance(s, dict) and s.get("meter_code") == meter]
            if match is None or (match and not _count_usage(match[0], quantity)):
                self._delete(every)
            elif match:
                self._set(every, json.dumps(items), self._ent_ttl)
        return result

    def handle_webhook(self, event: Mapping[str, Any]) -> bool:
        """Invalidate by the type of an already verified event (``construct_event``); return whether it did anything."""
        kind = str(event.get("type", ""))
        if kind in _CATALOG_EVENTS:
            self.invalidate_catalog()
            return True
        if kind not in _CUSTOMER_EVENTS and not kind.startswith("subscription."):
            return False
        data = event.get("data") or {}
        customer = data.get("customer_external_id") or (data.get("subscription") or {}).get("customer_external_id")
        if not customer:
            return False
        self.invalidate_customer(customer)
        return True

    def invalidate_customer(self, customer: str) -> None:
        """The customer's next entitlement checks ask the API."""
        self._set(self._customer_gen_key(customer), _token(), self._gen_ttl)

    def invalidate_catalog(self) -> None:
        """The next catalog read asks the API."""
        self._set(self._base + "gen:catalog", _token(), self._gen_ttl)

    def _customer_gen_key(self, customer: str) -> str:
        return self._base + f"gen:c:{customer}"

    def _generation(self, key: str) -> str | None:
        # A missing generation is created. It is not refreshed on read: re-setting the value read could undo an
        # invalidation written in between, and a generation that expires costs one miss (the new one is random).
        ok, gen = self._get(key)
        if not ok:
            return None
        if gen:
            return gen
        fresh = _token()
        return fresh if self._set(key, fresh, self._gen_ttl) else None

    def _cached(self, gen_key: str, key_of: Callable[[str], str], ttl: int, load: Callable[[], T]) -> T:
        gen = self._generation(gen_key)
        if gen is None:
            return load()
        key = self._base + key_of(gen)
        ok, raw = self._get(key)
        if ok and raw:
            hit = _parse(raw)
            if hit is not None:
                return cast("T", hit)
        value = load()
        self._set(key, json.dumps(value), ttl)
        return value

    def _get(self, key: str) -> tuple[bool, str | None]:
        try:
            return True, self._store.get(key)
        except Exception as err:
            self._on_error(err)
            return False, None

    def _set(self, key: str, value: str, ttl: int) -> bool:
        try:
            self._store.set(key, value, ttl)
            return True
        except Exception as err:
            self._on_error(err)
            return False

    def _delete(self, key: str) -> None:
        try:
            self._store.delete(key)
        except Exception as err:
            self._on_error(err)


def _token() -> str:
    return secrets.token_hex(8)


def _parse(raw: str) -> Any:
    try:
        return json.loads(raw)
    except ValueError:
        return None


def _count_usage(status: dict[str, Any], quantity: str) -> bool:
    """Add ``quantity`` to ``used`` and recompute ``over_limit``, and ``allowed`` for a hard limit, in place. False:
    the entry lacks what is needed (the caller then drops it)."""
    try:
        used = Decimal(status["used"]) + Decimal(quantity)
        limit = None if status.get("unlimited") or status.get("limit") is None else Decimal(status["limit"])
    except (KeyError, TypeError, InvalidOperation):
        return False
    status["used"] = _plain(used)
    if limit is not None:
        status["over_limit"] = used > limit
        if status.get("mode") == "hard":
            status["allowed"] = not status.get("suspended") and used < limit
    return True


def _plain(d: Decimal) -> str:
    text = format(d, "f")
    return text.rstrip("0").rstrip(".") if "." in text else text
