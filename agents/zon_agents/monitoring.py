"""Error tracking (Sentry) of the agents service — the same contract as the backend's (backend/config/sentry.php).

Off while SENTRY_DSN is empty. An unexpected error (the 500 of `main.request_log`) is sent with:
- its stack — code lines, never the values of variables;
- the route, and the request's `X-Request-Id` as the tag `request_id`: Laravel's trace id, so the same action is
  found in Laravel's Sentry project, in its logs, in «تتبّع طلب» and in /var/log/zon/agents.log;
- the deployed version (`SENTRY_RELEASE`).

Never: the request body (the user's notes, listings, questions), the prompts or the model's answers, the internal
key and the index signature, cookies, query strings. The model, embedding and vector calls stay breadcrumbs (host,
path, status). PydanticAI's, OpenAI's and MCP's own Sentry integrations are off: they exist to record prompts.
"""

import logging
from typing import TYPE_CHECKING

import sentry_sdk
from sentry_sdk.integrations.logging import LoggingIntegration
from sentry_sdk.integrations.mcp import MCPIntegration
from sentry_sdk.integrations.openai import OpenAIIntegration
from sentry_sdk.integrations.pydantic_ai import PydanticAIIntegration

from zon_agents import __version__
from zon_agents.logs import request_id_var

if TYPE_CHECKING:
    from sentry_sdk._types import DataCollectionUserOptions, Event, Hint
    from sentry_sdk.transport import Transport

    from zon_agents.config import Settings

TRACE_TAG = "request_id"

# The headers an error may carry. Everything else is dropped by the SDK (X-Internal-Key, X-Signature, …).
SAFE_HEADERS = ["content-type", "user-agent", "x-request-id"]

DATA_COLLECTION: "DataCollectionUserOptions" = {
    "user_info": False,
    "cookies": {"mode": "off"},
    "http_headers": {"request": {"mode": "allowlist", "terms": SAFE_HEADERS}},
    "http_bodies": [],
    "url_query_params": {"mode": "off"},
    "gen_ai": {"inputs": False, "outputs": False},
    "database_query_data": False,
    "queues": False,
    "stack_frame_variables": False,
    "frame_context_lines": 5,
}


def _before_send(event: "Event", hint: "Hint") -> "Event":
    request_id = request_id_var.get()
    if request_id:
        event.setdefault("tags", {})[TRACE_TAG] = request_id
    request = event.get("request")
    if isinstance(request, dict):  # belt and braces over DATA_COLLECTION: a body never leaves
        request.pop("data", None)
        request.pop("cookies", None)
        request.pop("query_string", None)
    return event


def init(settings: "Settings", *, transport: "Transport | None" = None) -> bool:
    """Starts Sentry when SENTRY_DSN is set; True when it did."""
    dsn = settings.sentry_dsn.get_secret_value() if settings.sentry_dsn else ""
    if not dsn:
        return False
    sentry_sdk.init(
        dsn=dsn,
        environment=settings.sentry_environment,
        release=settings.sentry_release or f"zon-agents@{__version__}",
        traces_sample_rate=settings.sentry_traces_sample_rate,
        data_collection=DATA_COLLECTION,  # supersedes send_default_pii (deprecated in this SDK)
        max_request_body_size="never",
        include_local_variables=False,
        before_send=_before_send,
        # Our JSON log lines (ids and timings, never text) become breadcrumbs; errors are sent by `capture` only.
        integrations=[LoggingIntegration(level=logging.INFO, event_level=None)],
        disabled_integrations=[OpenAIIntegration(), PydanticAIIntegration(), MCPIntegration()],
        transport=transport,
    )
    return True


def capture(error: BaseException) -> None:
    """An unexpected error of a request (nothing happens while Sentry is off)."""
    sentry_sdk.capture_exception(error)


def enabled() -> bool:
    """Whether errors reach Sentry: a client with a DSN (a client without one sends nothing)."""
    return sentry_sdk.get_client().dsn is not None
