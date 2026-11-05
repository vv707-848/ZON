"""HTTP surface (contract §1): agent calls, index signals, image hashes, health — and the MCP mount.

Every request writes ONE JSON line: ids, timings, tokens, never text. Its `request_id` is the X-Request-Id
Laravel sent — the trace id of the user's action — and it is echoed back.
"""

import asyncio
import json
import logging
import time
import uuid
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request, Response
from fastapi.responses import JSONResponse
from pydantic import ValidationError
from starlette.types import ASGIApp, Receive, Scope, Send

from zon_agents import __version__, monitoring
from zon_agents.config import Settings
from zon_agents.errors import DependencyUnavailable, InvalidPayload
from zon_agents.indexing import Indexer
from zon_agents.logs import event, request_id_var
from zon_agents.mcp_server import build_mcp
from zon_agents.pipelines import PIPELINES
from zon_agents.pipelines.base import Ctx
from zon_agents.schemas.index import IndexSignal
from zon_agents.security import key_matches, signature_valid
from zon_agents.services import Services, build_services
from zon_agents.tools.retrieval import Retrieval

# The model step stops at the request budget (then ranking agents answer degraded); this margin bounds the
# whole call so that even slow retrieval answers before Laravel's 20 s cut-off.
RETRIEVAL_MARGIN_SECONDS = 2.0


class HealthCheck:
    """GET /agents/health: short probes, cached a few seconds and shared by concurrent callers."""

    def __init__(self, services: Services, ttl_seconds: float) -> None:
        self._services = services
        self._ttl = ttl_seconds
        self._lock = asyncio.Lock()
        self._cached: dict[str, Any] | None = None
        self._at = 0.0

    @staticmethod
    async def _probe(check: Callable[[], Awaitable[bool]]) -> bool:
        try:
            async with asyncio.timeout(2.5):
                return await check()
        except Exception:  # a probe failing in any way means "not healthy"
            return False

    async def status(self) -> dict[str, Any]:
        async with self._lock:
            if self._cached is None or time.monotonic() - self._at > self._ttl:
                s = self._services
                llm, embed, qdrant = await asyncio.gather(
                    self._probe(s.llm.healthy), self._probe(s.embedder.healthy), self._probe(s.store.healthy)
                )
                model = s.llm.profile.name  # the profile the AI node serves (LLM_PROFILE)
                errors = monitoring.enabled()  # SENTRY_DSN set: unexpected errors reach Sentry
                self._cached = {
                    "status": "ok",
                    "llm": llm,
                    "embed": embed,
                    "qdrant": qdrant,
                    "model": model,
                    "errors": errors,
                }
                self._at = time.monotonic()
            return self._cached


class RequireKey:
    """ASGI guard of the MCP mount: the same X-Internal-Key as the agent routes."""

    def __init__(self, app: ASGIApp, key: str) -> None:
        self._app = app
        self._key = key

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] == "http":
            headers = {k.decode().lower(): v.decode() for k, v in scope.get("headers", [])}
            if not key_matches(headers.get("x-internal-key"), self._key):
                await JSONResponse({"detail": "invalid internal key"}, status_code=401)(scope, receive, send)
                return
        await self._app(scope, receive, send)


def create_app(settings: Settings, services: Services | None = None) -> FastAPI:
    services = services or build_services(settings)
    ctx = Ctx(
        llm=services.llm,
        retrieval=Retrieval(services.embedder, services.store, settings.knowledge_top_k, settings.knowledge_min_score),
    )
    indexer = Indexer(services.embedder, services.store, services.versions)
    health = HealthCheck(services, settings.health_cache_seconds)
    mcp = build_mcp(ctx)
    mcp_app = mcp.streamable_http_app(streamable_http_path="/", stateless_http=True, json_response=True)
    internal_key = settings.agents_internal_key.get_secret_value()
    index_secret = settings.index_shared_secret.get_secret_value()
    prompts = services.llm.prompts
    if set(prompts) != set(PIPELINES):
        raise RuntimeError(f"prompt files and agents differ: {sorted(set(prompts) ^ set(PIPELINES))}")

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        event("startup", version=__version__, phash=services.hasher is not None, colbert=settings.colbert_enabled)
        async with mcp.session_manager.run():
            yield
        await services.aclose()

    app = FastAPI(
        title="zon-agents", version=__version__, lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None
    )

    def require_key(x_internal_key: str | None = Header(default=None)) -> None:
        if not key_matches(x_internal_key, internal_key):
            raise HTTPException(status_code=401, detail="invalid internal key")

    @app.middleware("http")
    async def request_log(request: Request, call_next: Callable[[Request], Awaitable[Response]]) -> Response:
        """One JSON line per request (ids, timings, tokens — never payload text); X-Request-Id echoed."""
        request_id = request.headers.get("x-request-id") or uuid.uuid4().hex
        token = request_id_var.set(request_id)
        started = time.perf_counter()
        request.state.log = {}
        try:
            try:
                response = await call_next(request)
            except Exception as error:
                event("unhandled", logging.ERROR, exc_info=True, path=request.url.path)
                monitoring.capture(error)  # Sentry, with the request id and without the body (monitoring.py)
                response = JSONResponse({"detail": "internal error"}, status_code=500)
            event(
                "request",
                method=request.method,
                path=request.url.path,
                status=response.status_code,
                latency_ms=round((time.perf_counter() - started) * 1000),
                **request.state.log,
            )
            response.headers["X-Request-Id"] = request_id
            return response
        finally:
            request_id_var.reset(token)

    @app.get("/agents/health")
    async def agents_health() -> dict[str, Any]:
        return await health.status()

    @app.post("/agents/{agent}", dependencies=[Depends(require_key)])
    async def call_agent(agent: str, request: Request) -> JSONResponse:
        pipeline = PIPELINES.get(agent)
        if pipeline is None:
            raise HTTPException(status_code=404, detail="unknown agent")
        log: dict[str, Any] = request.state.log
        log["agent"] = agent
        log["prompt"] = prompts[agent].version
        try:
            data = json.loads(await request.body() or b"{}")
        except ValueError:
            return JSONResponse({"detail": "body is not JSON"}, status_code=422)
        if not isinstance(data, dict):
            return JSONResponse({"detail": "body must be an object"}, status_code=422)
        started = time.perf_counter()
        try:
            async with asyncio.timeout(settings.request_budget_seconds + RETRIEVAL_MARGIN_SECONDS):
                result = await pipeline(ctx, data)
        except InvalidPayload as exc:
            return JSONResponse({"detail": "invalid input", "fields": exc.locations}, status_code=422)
        except DependencyUnavailable as exc:
            log["unavailable"] = exc.dependency
            return JSONResponse({"detail": str(exc)}, status_code=503)
        except TimeoutError:
            log["unavailable"] = "timeout"
            return JSONResponse({"detail": "timeout"}, status_code=503)
        log.update(degraded=result.degraded, tokens_in=result.tokens_in, tokens_out=result.tokens_out, **result.log)
        return JSONResponse(
            {
                "output": result.output,
                "degraded": result.degraded,
                "usage": {"tokens_in": result.tokens_in, "tokens_out": result.tokens_out},
                "latency_ms": round((time.perf_counter() - started) * 1000),
            }
        )

    @app.post("/index/{entity}")
    async def index_signal(entity: str, request: Request) -> JSONResponse:
        body = await request.body()
        valid = signature_valid(
            index_secret,
            request.headers.get("x-timestamp"),
            body,
            request.headers.get("x-signature"),
            settings.index_max_skew_seconds,
        )
        if not valid:
            return JSONResponse({"detail": "invalid signature"}, status_code=401)
        try:
            signal = IndexSignal.model_validate_json(body)
        except ValidationError as exc:
            fields = [".".join(str(p) for p in e["loc"]) for e in exc.errors()][:10]
            return JSONResponse({"detail": "invalid signal", "fields": fields}, status_code=422)
        if signal.entity != entity:
            return JSONResponse({"detail": "entity differs from the path"}, status_code=422)
        log: dict[str, Any] = request.state.log
        log.update(entity=entity, entity_id=signal.entity_id, version=signal.version, action=signal.action)
        try:
            status = await indexer.handle(signal)
        except InvalidPayload as exc:
            return JSONResponse({"detail": "invalid payload", "fields": exc.locations}, status_code=422)
        except DependencyUnavailable as exc:
            log["unavailable"] = exc.dependency
            return JSONResponse({"detail": str(exc)}, status_code=503)
        log["result"] = status
        return JSONResponse({"status": status})

    @app.post("/media/phash", dependencies=[Depends(require_key)])
    async def media_phash(request: Request) -> JSONResponse:
        if services.hasher is None:
            return JSONResponse({"detail": "image hashing is not installed (extra 'phash')"}, status_code=501)
        body = await request.body()
        if not body or len(body) > settings.phash_max_bytes:
            return JSONResponse({"detail": "empty or too large"}, status_code=413)
        try:
            value = await services.hasher.phash(body)
        except (OSError, ValueError):
            return JSONResponse({"detail": "not an image"}, status_code=422)
        return JSONResponse({"phash": value})

    app.mount("/mcp", RequireKey(mcp_app, internal_key))
    return app
