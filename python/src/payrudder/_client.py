"""The sync and async clients."""

from __future__ import annotations

import asyncio
import time
from collections.abc import Awaitable, Callable, Mapping, Sequence
from types import TracebackType
from typing import Any, cast

import httpx

from . import _core
from . import models as m
from .cache import PayrudderCache
from .resources import AsyncResources, Resources
from .types import RequestOptions


class Payrudder(Resources):
    """Synchronous client for the Payrudder tenant API (``/v1/*``).

    >>> client = Payrudder(api_key="prk_test_...")
    >>> client.customers.get_customer("cus_42")

    Args:
        api_key: ``prk_test_...`` / ``prk_live_...``; falls back to ``PAYRUDDER_API_KEY``.
        base_url: falls back to ``PAYRUDDER_BASE_URL``, then ``http://localhost:42800``.
        timeout: seconds per request (default 30).
        max_retries: automatic retries for network errors, 429, 502/503/504 and (idempotent calls) 500. Default 2.
        http_client: your own ``httpx.Client`` (not closed by this client). Mutually exclusive with ``transport``.
        transport: an ``httpx.BaseTransport`` (for tests, proxies, mTLS) used to build the internal client.
        cache: the options of a ``PayrudderCache`` (``store``, ``access_ttl_ms``, ...); with it, ``access``,
            ``record_usage`` and ``handle_webhook`` go through the cache. ``{}`` is an in-memory cache.
    """

    def __init__(
        self,
        api_key: str | None = None,
        *,
        base_url: str | None = None,
        timeout: float = _core.DEFAULT_TIMEOUT,
        max_retries: int = _core.DEFAULT_MAX_RETRIES,
        user_agent: str | None = None,
        default_headers: Mapping[str, str] | None = None,
        http_client: httpx.Client | None = None,
        transport: httpx.BaseTransport | None = None,
        cache: Mapping[str, Any] | None = None,
    ) -> None:
        if http_client is not None and transport is not None:
            raise ValueError("pass http_client or transport, not both")
        self._settings = _core.resolve_settings(api_key, base_url, timeout, max_retries, user_agent, default_headers)
        self._owns_http = http_client is None
        self._http = http_client or httpx.Client(transport=transport)
        self._sleep: Callable[[float], None] = time.sleep
        self._init_resources(self)
        self.cache: PayrudderCache | None = PayrudderCache(self, **cache) if cache is not None else None

    def connect(
        self,
        *,
        id: str,  # noqa: A002 - your own id of the customer, as the API names it
        name: str,
        email: str | None = None,
        locale: str | None = None,
        plan: str | None = None,
        member: Mapping[str, str] | None = None,
        expires_in_days: int | None = None,
    ) -> m.ConnectCustomerResponse:
        """Create or update the customer and return the link to their customer page (``url`` is None for a member who
        does not manage payment)."""
        return self.customers.connect_customer(id, _connect_body(name, email, locale, plan, member, expires_in_days))

    def access(self, customer: str, meters: str | Sequence[str] = (), *, member: str | None = None) -> m.CustomerAccess:
        """Whether the customer may use the service now, by subscription, payment and the meters given. Cached when
        the client has a cache."""
        listed = [meters] if isinstance(meters, str) else list(meters)
        if self.cache is not None:
            return self.cache.access(customer, listed, member)
        return self.customers.get_customer_access(customer, meters=",".join(listed) or None, member=member)

    def record_usage(self, event: m.IncomingEvent) -> m.EventResult:
        """Record one usage event; with a cache, count it into the cached answers too."""
        if self.cache is not None:
            return self.cache.record_usage(event)
        return self.events.ingest([event])[0]

    def handle_webhook(self, event: Mapping[str, Any]) -> bool:
        """Invalidate cached answers for a verified webhook event; False without a cache."""
        return self.cache.handle_webhook(event) if self.cache is not None else False

    @property
    def base_url(self) -> str:
        return self._settings.base_url

    @property
    def environment(self) -> str:
        """The API key's environment: ``"test"`` for a ``prk_test_`` key, ``"live"`` for every other key."""
        return "test" if self._settings.api_key.startswith("prk_test_") else "live"

    def close(self) -> None:
        if self._owns_http:
            self._http.close()

    def __enter__(self) -> Payrudder:
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None, exc: BaseException | None, tb: TracebackType | None
    ) -> None:
        self.close()

    def _request(
        self,
        method: str,
        path: str,
        *,
        query: Mapping[str, Any] | None = None,
        body: Any = None,
        body_wire: Any = None,
        files: Any = None,
        form: Mapping[str, Any] | None = None,
        returns: str = "json",
        response_wire: Any = None,
        options: RequestOptions | None = None,
    ) -> Any:
        prepared = _core.prepare(
            self._settings, method, path, query=query, body=body, body_wire=body_wire, files=files, form=form,
            returns=returns, response_wire=response_wire, options=options,
        )  # fmt: skip
        attempt = 0
        while True:
            try:
                response = self._http.request(
                    prepared.method,
                    prepared.url,
                    headers=prepared.headers,
                    params=prepared.params,
                    content=prepared.content,
                    files=prepared.files,
                    data=prepared.data,
                    timeout=prepared.timeout,
                )
            except httpx.TransportError as exc:
                if attempt < prepared.max_retries and _core.transport_retriable(exc, prepared.idempotent):
                    self._sleep(_core.backoff_delay(attempt, None))
                    attempt += 1
                    continue
                raise _core.connection_error(exc) from exc
            if response.is_success:
                return _core.parse_success(response, prepared)
            error = _core.error_from_response(response)
            if attempt < prepared.max_retries and _core.status_retriable(
                response.status_code, prepared.idempotent, error.retry_after
            ):
                self._sleep(_core.backoff_delay(attempt, error.retry_after))
                attempt += 1
                continue
            raise error


class AsyncPayrudder(AsyncResources):
    """Asynchronous client; the same methods as :class:`Payrudder`, awaited. Paginators are async generators."""

    def __init__(
        self,
        api_key: str | None = None,
        *,
        base_url: str | None = None,
        timeout: float = _core.DEFAULT_TIMEOUT,
        max_retries: int = _core.DEFAULT_MAX_RETRIES,
        user_agent: str | None = None,
        default_headers: Mapping[str, str] | None = None,
        http_client: httpx.AsyncClient | None = None,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        if http_client is not None and transport is not None:
            raise ValueError("pass http_client or transport, not both")
        self._settings = _core.resolve_settings(api_key, base_url, timeout, max_retries, user_agent, default_headers)
        self._owns_http = http_client is None
        self._http = http_client or httpx.AsyncClient(transport=transport)
        self._sleep: Callable[[float], Awaitable[None]] = asyncio.sleep
        self._init_resources(self)

    async def connect(
        self,
        *,
        id: str,  # noqa: A002 - your own id of the customer, as the API names it
        name: str,
        email: str | None = None,
        locale: str | None = None,
        plan: str | None = None,
        member: Mapping[str, str] | None = None,
        expires_in_days: int | None = None,
    ) -> m.ConnectCustomerResponse:
        """Create or update the customer and return the link to their customer page."""
        return await self.customers.connect_customer(
            id, _connect_body(name, email, locale, plan, member, expires_in_days)
        )

    async def access(
        self, customer: str, meters: str | Sequence[str] = (), *, member: str | None = None
    ) -> m.CustomerAccess:
        """Whether the customer may use the service now, by subscription, payment and the meters given. Not cached:
        ``PayrudderCache`` works with the sync client."""
        listed = [meters] if isinstance(meters, str) else list(meters)
        return await self.customers.get_customer_access(customer, meters=",".join(listed) or None, member=member)

    @property
    def base_url(self) -> str:
        return self._settings.base_url

    @property
    def environment(self) -> str:
        """The API key's environment: ``"test"`` for a ``prk_test_`` key, ``"live"`` for every other key."""
        return "test" if self._settings.api_key.startswith("prk_test_") else "live"

    async def aclose(self) -> None:
        if self._owns_http:
            await self._http.aclose()

    async def __aenter__(self) -> AsyncPayrudder:
        return self

    async def __aexit__(
        self, exc_type: type[BaseException] | None, exc: BaseException | None, tb: TracebackType | None
    ) -> None:
        await self.aclose()

    async def _request(
        self,
        method: str,
        path: str,
        *,
        query: Mapping[str, Any] | None = None,
        body: Any = None,
        body_wire: Any = None,
        files: Any = None,
        form: Mapping[str, Any] | None = None,
        returns: str = "json",
        response_wire: Any = None,
        options: RequestOptions | None = None,
    ) -> Any:
        prepared = _core.prepare(
            self._settings, method, path, query=query, body=body, body_wire=body_wire, files=files, form=form,
            returns=returns, response_wire=response_wire, options=options,
        )  # fmt: skip
        attempt = 0
        while True:
            try:
                response = await self._http.request(
                    prepared.method,
                    prepared.url,
                    headers=prepared.headers,
                    params=prepared.params,
                    content=prepared.content,
                    files=prepared.files,
                    data=prepared.data,
                    timeout=prepared.timeout,
                )
            except httpx.TransportError as exc:
                if attempt < prepared.max_retries and _core.transport_retriable(exc, prepared.idempotent):
                    await self._sleep(_core.backoff_delay(attempt, None))
                    attempt += 1
                    continue
                raise _core.connection_error(exc) from exc
            if response.is_success:
                return _core.parse_success(response, prepared)
            error = _core.error_from_response(response)
            if attempt < prepared.max_retries and _core.status_retriable(
                response.status_code, prepared.idempotent, error.retry_after
            ):
                await self._sleep(_core.backoff_delay(attempt, error.retry_after))
                attempt += 1
                continue
            raise error


def _connect_body(
    name: str,
    email: str | None,
    locale: str | None,
    plan: str | None,
    member: Mapping[str, str] | None,
    expires_in_days: int | None,
) -> m.ConnectCustomerRequest:
    body: dict[str, Any] = {"name": name}
    for k, v in (("email", email), ("locale", locale), ("plan_code", plan), ("expires_in_days", expires_in_days)):
        if v is not None:
            body[k] = v
    if member is not None:
        body["member"] = dict(member)
    return cast("m.ConnectCustomerRequest", body)
