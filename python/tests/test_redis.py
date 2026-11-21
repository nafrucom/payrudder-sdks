"""RedisStore against a real Redis through redis-py. Skipped unless PAYRUDDER_TEST_REDIS_URL is set, e.g.

docker run -d --rm -p 6390:6379 redis:7-alpine && PAYRUDDER_TEST_REDIS_URL=redis://localhost:6390 pytest tests/test_redis.py
"""

from __future__ import annotations

import os
import time
import uuid
from collections.abc import Iterator

import pytest

from payrudder.cache import RedisStore

from .test_cache import FakeClient, make

URL = os.environ.get("PAYRUDDER_TEST_REDIS_URL")
pytestmark = pytest.mark.skipif(not URL, reason="PAYRUDDER_TEST_REDIS_URL is not set")


@pytest.fixture(params=[False, True], ids=["bytes", "decoded"])
def store(request: pytest.FixtureRequest) -> Iterator[RedisStore]:
    import redis

    client = redis.Redis.from_url(URL or "", decode_responses=request.param)
    yield RedisStore(client)
    client.close()


def key(k: str) -> str:
    return f"payrudder-sdk-test:py:{uuid.uuid4().hex}:{k}"


def test_set_get_delete_and_expiry(store: RedisStore) -> None:
    k = key("a")
    assert store.get(k) is None
    store.set(k, "v1", 60_000)
    assert store.get(k) == "v1"
    store.delete(k)
    assert store.get(k) is None
    store.set(k, "short", 150)
    assert store.get(k) == "short"
    time.sleep(0.4)
    assert store.get(k) is None


def test_a_webhook_invalidates_what_another_instance_cached(store: RedisStore) -> None:
    client = FakeClient()
    prefix = key("cache") + ":"

    def fail(e: Exception) -> None:
        raise e

    one = make(client, store=store, prefix=prefix, on_error=fail)
    two = make(client, store=store, prefix=prefix, on_error=fail)
    assert one.entitlement("cus", "api")["used"] == "8"
    assert two.entitlement("cus", "api")["used"] == "8"
    assert client.calls == ["ent cus api"]  # the second instance read the first one's entry
    client.used = "9"
    assert two.handle_webhook({"type": "entitlement.updated", "data": {"customer_external_id": "cus"}})
    assert one.entitlement("cus", "api")["used"] == "9"
    assert client.calls == ["ent cus api", "ent cus api"]
