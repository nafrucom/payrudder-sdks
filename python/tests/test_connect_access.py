from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

import httpx

from payrudder import Payrudder

ACCESS = {
    "customer_external_id": "u1",
    "allowed": True,
    "reason": "ok",
    "subscription": {"id": "s1", "state": "SUBSCRIPTION_STATE_ACTIVE", "plan_code": "pro", "trial_ends_at": None, "cancel_at_period_end": False},
    "meters": [{"customer_external_id": "u1", "meter_code": "api", "used": "9", "limit": "10", "unlimited": False,
                "allowed": True, "mode": "hard", "over_limit": False, "suspended": False}],
    "notices": [{"code": "limit_near", "severity": "warning", "meter_code": "api", "at": None}],
}  # fmt: skip


class Api:
    def __init__(self) -> None:
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        if request.url.path.endswith("/session"):
            return httpx.Response(200, json={"url": "https://x/c/tok", "expires_at": "2026-11-22T00:00:00Z",
                                             "customer": {"external_id": "u1"}, "created": True, "billing_owner": True})  # fmt: skip
        if request.url.path == "/v1/events":
            return httpx.Response(200, json={"results": [{"status": "accepted"}]})
        return httpx.Response(200, json=ACCESS)

    @property
    def accesses(self) -> int:
        return sum(1 for r in self.requests if r.url.path.endswith("/access"))


def client(cache: dict[str, Any] | None = None) -> tuple[Payrudder, Api]:
    api = Api()
    return Payrudder(
        "prk_test_x", base_url="http://api.test", max_retries=0, transport=httpx.MockTransport(api), cache=cache
    ), api


def test_connect_request() -> None:
    p, api = client()
    out = p.connect(id="u1", name="Acme", plan="pro", member={"id": "m1", "name": "Olga"})
    req = api.requests[0]
    assert req.method == "PUT"
    assert req.url.path == "/v1/customers/u1/session"
    assert json.loads(req.content) == {"name": "Acme", "plan_code": "pro", "member": {"id": "m1", "name": "Olga"}}
    assert out["url"] == "https://x/c/tok"


def test_access_no_cache() -> None:
    p, api = client()
    p.access("u1", ["a", "b"])
    p.access("u1", ["a", "b"])
    assert api.accesses == 2
    assert api.requests[0].url.params["meters"] == "a,b"


def test_access_cached() -> None:
    p, api = client({})
    p.access("u1", "api")
    assert p.access("u1", "api")["allowed"] is True
    assert api.accesses == 1


def test_access_meter_order() -> None:
    p, api = client({})
    p.access("u1", ["a", "b"])
    p.access("u1", ["b", "a"])
    assert api.accesses == 1


def test_access_invalidated_by_invoice_paid() -> None:
    p, api = client({})
    p.access("u1", "api")
    assert p.handle_webhook({"type": "invoice.paid", "data": {"customer_external_id": "u1"}})
    p.access("u1", "api")
    assert api.accesses == 2


def test_record_usage_flips_access() -> None:
    p, api = client({})
    p.access("u1", "api")
    p.record_usage(
        {"customer_external_id": "u1", "meter_code": "api", "quantity": "1", "event_time": datetime.now(timezone.utc)}
    )
    a = p.access("u1", "api")
    assert a["allowed"] is False
    assert a["reason"] == "limit_reached"
    assert a["meters"][0]["used"] == "10"
    assert a["notices"] == [{"code": "limit_reached", "severity": "critical", "meter_code": "api", "at": None}]
    assert api.accesses == 1
