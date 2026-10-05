from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager
from time import perf_counter

from typing import Annotated

from fastapi import Depends, FastAPI, Response, status
from prometheus_client import CONTENT_TYPE_LATEST, Counter, Gauge, Histogram, generate_latest
from uvicorn.middleware.proxy_headers import ProxyHeadersMiddleware

from app.api import schemas
from app.api.access import router as access_router
from app.api.actions import router as actions_router
from app.api.auth import router as auth_router
from app.api.dashboards import router as dashboards_router
from app.api.device_access import router as device_access_router
from app.api.device_groups import router as device_groups_router
from app.api.device_models import router as device_models_router
from app.api.events import router as events_router
from app.api.macros import macros_router, registration_router
from app.api.maintenance import router as maintenance_router
from app.api.devices import router as devices_router
from app.api.mib import router as mib_router
from app.api.polling import router as polling_router
from app.api.realtime import router as realtime_router
from app.api.schedule import router as schedule_router
from app.api.tokens import router as tokens_router
from app.api.traps import router as traps_router
from app.api.users import router as users_router
from app.api.vendors import router as vendors_router
from app.core.config import settings
from app.core.database import check_postgres, create_pool
from app.core.logging import configure_logging
from app.core.redis import check_redis, create_redis
from app.core.security import public
from app.realtime.bus import run_subscriber
from app.realtime.manager import ConnectionManager
from app.schema import ensure_schema_current

configure_logging()
logger = logging.getLogger("cybersathy.api")

REQUESTS = Counter("cybersathy_api_requests_total", "Total API requests", ["route", "method", "status"])
LATENCY = Histogram("cybersathy_api_request_seconds", "API request latency", ["route", "method"])
READY = Gauge("cybersathy_api_ready", "Readiness status: 1 ready, 0 not ready")


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.pool = await create_pool()
    app.state.redis = create_redis()
    app.state.realtime_manager = ConnectionManager()
    stop_realtime = asyncio.Event()
    subscriber_task = asyncio.create_task(run_subscriber(app.state.redis, app.state.realtime_manager, stop=stop_realtime))
    try:
        if settings.check_schema_on_start:
            await ensure_schema_current(app.state.pool)
        yield
    finally:
        stop_realtime.set()
        await subscriber_task
        await app.state.redis.aclose()
        await app.state.pool.close()


app = FastAPI(
    title=settings.app_name,
    version="0.2.0",
    docs_url=f"{settings.api_prefix}/docs",
    openapi_url=f"{settings.api_prefix}/openapi.json",
    lifespan=lifespan,
)

# The client address is whatever a trusted proxy says it is, and never anything a client sends directly.
app.add_middleware(ProxyHeadersMiddleware, trusted_hosts=list(settings.trusted_proxies))

for router in (auth_router, access_router, users_router, tokens_router, devices_router, vendors_router, device_access_router, device_groups_router, polling_router, schedule_router, device_models_router, mib_router, events_router, maintenance_router, traps_router, realtime_router, dashboards_router, actions_router, macros_router, registration_router):
    app.include_router(router)


@app.middleware("http")
async def metrics_middleware(request, call_next):
    start = perf_counter()
    response = await call_next(request)
    elapsed = perf_counter() - start
    # Label with the route template, never the raw URL: `/devices/{device_id}` is one series, not one per device,
    # and a scanner probing random paths all lands in "unmatched".
    route = request.scope.get("route")
    label = getattr(route, "path", None) or "unmatched"
    REQUESTS.labels(route=label, method=request.method, status=str(response.status_code)).inc()
    LATENCY.labels(route=label, method=request.method).observe(elapsed)
    return response


@app.get("/health", response_model=schemas.HealthOut)
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/ready", response_model=schemas.ReadyOut)
async def ready(response: Response) -> dict[str, object]:
    checks: dict[str, str] = {}
    for name, probe in (("postgres", lambda: check_postgres(app.state.pool)), ("redis", lambda: check_redis(app.state.redis))):
        try:
            checks[name] = (await probe())["status"]
        except Exception:  # noqa: BLE001 - the detail goes to the log, never to the caller
            logger.exception("readiness check failed: %s", name)
            checks[name] = "error"
    is_ready = all(value == "ok" for value in checks.values())
    READY.set(1 if is_ready else 0)
    if not is_ready:
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
    return {"ready": is_ready, "checks": checks}


@app.get("/metrics")
async def metrics() -> Response:
    # Not proxied by Nginx: Prometheus scrapes this over the internal network only.
    return Response(generate_latest(), media_type=CONTENT_TYPE_LATEST)


@app.get(f"{settings.api_prefix}/health", response_model=schemas.HealthOut)
async def api_health(_: Annotated[None, Depends(public)]) -> dict[str, str]:
    return {"status": "ok"}
