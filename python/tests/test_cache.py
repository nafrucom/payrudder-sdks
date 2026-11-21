from __future__ import annotations

from types import SimpleNamespace
from typing import Any, cast

from payrudder import Payrudder
from payrudder.cache import MemoryStore, PayrudderCache, RedisStore


class FakeClient:
    """Answers the reads PayrudderCache makes and records each call."""

    def __init__(self, environment: str = "test", used: str = "8") -> None:
        self.environment = environment
        self.used = used
        self.calls: list[str] = []
        self.customers = SimpleNamespace(get_entitlement=self._ent, list_entitlements=self._ents)
        self.plans = SimpleNamespace(list_plans=self._plans, get_plan_version=self._version)
        self.credit_packs = SimpleNamespace(list_credit_packs=self._packs)
        self.campaigns = SimpleNamespace(list_campaigns=self._campaigns)
        self.events = SimpleNamespace(ingest=self._ingest)

    def _status(self, customer: str, meter: str) -> dict[str, Any]:
        return {"customer_external_id": customer, "meter_code": meter, "used": self.used, "limit": "10",
                "unlimited": False, "allowed": True, "mode": "hard", "over_limit": False, "suspended": False}  # fmt: skip

    def _ent(self, c: str, m: str) -> dict[str, Any]:
        self.calls.append(f"ent {c} {m}")
        return self._status(c, m)

    def _ents(self, c: str) -> dict[str, Any]:
        self.calls.append(f"ents {c}")
        return {"items": [self._status(c, "api")]}

    def _plans(self) -> dict[str, Any]:
        self.calls.append("plans")
        return {"plans": [{"code": "pro", "latest_version": 2}, {"code": "draft", "latest_version": 0}]}

    def _version(self, code: str, v: str) -> dict[str, Any]:
        self.calls.append(f"version {code} {v}")
        return {"plan_code": code, "version": int(v), "prices": []}

    def _packs(self, *, active: bool | None = None) -> dict[str, Any]:
        self.calls.append(f"packs {active}")
        return {"packs": []}

    def _campaigns(self, *, status: str | None = None) -> dict[str, Any]:
        self.calls.append(f"campaigns {status}")
        return {"campaigns": []}

    def _ingest(self, events: list[Any]) -> list[dict[str, Any]]:
        self.calls.append(f"ingest {len(events)}")
        return [{"status": "accepted"}]


def make(client: FakeClient, **kw: Any) -> PayrudderCache:
    return PayrudderCache(cast(Payrudder, client), **kw)


def usage(quantity: str) -> Any:
    return {
        "customer_external_id": "cus",
        "meter_code": "api",
        "quantity": quantity,
        "event_time": "2026-11-20T00:00:00Z",
    }


def test_hits_and_catalog() -> None:
    api = FakeClient()
    cache = make(api)
    for _ in range(2):
        assert cache.entitlement("cus", "api")["used"] == "8"
        cache.entitlements("cus")
        catalog = cache.catalog()
    assert [p["version"]["plan_code"] for p in catalog["plans"]] == ["pro"]
    assert api.calls == ["ent cus api", "ents cus", "plans", "version pro 2", "packs True", "campaigns running"]


def test_webhooks_invalidate_customer_and_catalog() -> None:
    api = FakeClient()
    cache = make(api)
    cache.entitlement("cus", "api")
    cache.entitlement("other", "api")
    cache.catalog()
    api.used = "9"
    assert cache.handle_webhook(
        {"type": "subscription.changed", "data": {"subscription": {"customer_external_id": "cus"}}}
    )
    assert cache.entitlement("cus", "api")["used"] == "9"
    assert cache.entitlement("other", "api")["used"] == "8"
    assert cache.handle_webhook(
        {"type": "entitlement.updated", "data": {"customer_external_id": "cus", "meter_code": ""}}
    )
    assert not cache.handle_webhook({"type": "invoice.paid", "data": {"customer_external_id": "cus"}})
    assert not cache.handle_webhook({"type": "customer.blocked", "data": {}})
    cache.catalog()
    assert api.calls.count("plans") == 1
    assert cache.handle_webhook({"type": "campaign.started", "data": {}})
    cache.catalog()
    assert api.calls.count("plans") == 2


def test_test_and_live_apart() -> None:
    store = MemoryStore()
    test_api, live_api = FakeClient("test"), FakeClient("live", used="1")
    test, live = make(test_api, store=store), make(live_api, store=store)
    assert test.entitlement("cus", "api")["used"] == "8"
    assert live.entitlement("cus", "api")["used"] == "1"
    test.invalidate_customer("cus")
    live.entitlement("cus", "api")
    assert live_api.calls == ["ent cus api"]


def test_record_usage_crosses_hard_limit() -> None:
    api = FakeClient()
    cache = make(api)
    cache.entitlement("cus", "api")
    cache.entitlements("cus")
    cache.record_usage(usage("1.5"))
    s = cache.entitlement("cus", "api")
    assert (s["used"], s["allowed"], s["over_limit"]) == ("9.5", True, False)
    cache.record_usage(usage("0.5"))
    s = cache.entitlement("cus", "api")
    assert (s["used"], s["allowed"]) == ("10", False)
    every = cache.entitlements("cus")
    assert (every[0]["used"], every[0]["allowed"]) == ("10", False)
    assert [c for c in api.calls if not c.startswith("ingest")] == ["ent cus api", "ents cus"]


def test_drops_an_entry_it_cannot_update() -> None:
    api = FakeClient()
    store = MemoryStore()
    cache = make(api, store=store)
    cache.entitlement("cus", "api")
    gen = store.get("payrudder:test:gen:c:cus")
    store.set(f"payrudder:test:ent:cus:{gen}:api", '{"used": "lots"}', 60_000)
    cache.record_usage(usage("1"))
    cache.entitlement("cus", "api")
    assert api.calls.count("ent cus api") == 2


class BrokenStore:
    def get(self, key: str) -> str | None:
        raise ConnectionError("down")

    def set(self, key: str, value: str, ttl_ms: int) -> None:
        raise ConnectionError("down")

    def delete(self, key: str) -> None:
        raise ConnectionError("down")


def test_store_failure_reads_through() -> None:
    api = FakeClient()
    errors: list[Exception] = []
    cache = make(api, store=BrokenStore(), on_error=errors.append)
    assert cache.entitlement("cus", "api")["used"] == "8"
    cache.record_usage(usage("1"))
    cache.invalidate_catalog()
    assert "ent cus api" in api.calls
    assert len(errors) >= 3


def test_memory_store_expires_and_evicts() -> None:
    now = [0.0]
    store = MemoryStore(max_entries=2, now=lambda: now[0])
    store.set("a", "1", 1000)
    store.set("b", "2", 60_000)
    store.get("a")
    store.set("c", "3", 60_000)
    assert store.get("b") is None
    now[0] = 2
    assert store.get("a") is None
    assert store.get("c") == "3"


def test_redis_store_uses_redis_py_calls() -> None:
    seen: list[Any] = []

    class FakeRedis:
        def get(self, key: str) -> bytes | None:
            return b"v" if key == "k" else None

        def set(self, key: str, value: str, px: int | None = None) -> bool:
            seen.append((key, value, px))
            return True

        def delete(self, key: str) -> int:
            seen.append(("del", key))
            return 1

    store = RedisStore(FakeRedis())
    store.set("k", "v", 500)
    store.delete("k")
    assert store.get("k") == "v"
    assert store.get("x") is None
    assert seen == [("k", "v", 500), ("del", "k")]


def test_client_environment() -> None:
    assert Payrudder(api_key="prk_test_x").environment == "test"
    assert Payrudder(api_key="prk_live_x").environment == "live"
