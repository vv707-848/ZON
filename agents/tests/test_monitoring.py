"""What an unexpected error sends to Sentry (zon_agents/monitoring.py): the trace id and the route, and nothing of
the request — not the user's text, not the internal key. Sentry runs with a transport that keeps the envelopes here.
"""

import json
from collections.abc import Iterator
from typing import Any

import pytest
import sentry_sdk
from fastapi.testclient import TestClient
from pydantic import SecretStr
from sentry_sdk.envelope import Envelope
from sentry_sdk.transport import Transport

from tests.conftest import INTERNAL_KEY
from zon_agents import monitoring
from zon_agents.config import Settings
from zon_agents.main import create_app
from zon_agents.pipelines import PIPELINES
from zon_agents.services import Services


class Kept(Transport):
    def __init__(self) -> None:
        super().__init__()
        self.envelopes: list[Envelope] = []

    def capture_envelope(self, envelope: Envelope) -> None:
        self.envelopes.append(envelope)

    def events(self) -> list[dict[str, Any]]:
        items = [item for envelope in self.envelopes for item in envelope.items if item.type == "event"]
        return [item.payload.json for item in items if item.payload.json]


@pytest.fixture
def kept(settings: Settings) -> Iterator[Kept]:
    transport = Kept()
    dsn = SecretStr("https://public@o1.ingest.sentry.invalid/1")
    with_dsn = settings.model_copy(update={"sentry_dsn": dsn, "sentry_release": "zon-agents@test"})
    assert monitoring.init(with_dsn, transport=transport)
    yield transport
    sentry_sdk.init()  # no DSN: the other tests run without Sentry


def test_an_unexpected_error_carries_the_trace_id_and_none_of_the_request(
    kept: Kept, settings: Settings, services: Services, monkeypatch: pytest.MonkeyPatch
) -> None:
    async def boom(ctx: object, data: dict[str, Any]) -> None:
        raise RuntimeError("the pipeline broke")

    monkeypatch.setitem(PIPELINES, "chat", boom)
    secret_text = "أبحث عن فيلا في النرجس وجوالي 0551234567"
    with TestClient(create_app(settings, services)) as client:
        response = client.post(
            "/agents/chat?phone=0551234567",
            json={"message": secret_text, "history": []},
            headers={"X-Internal-Key": INTERNAL_KEY, "X-Request-Id": "trace-from-laravel-01"},
        )
        assert client.get("/agents/health").json()["errors"] is True

    assert response.status_code == 500
    sentry_sdk.flush()
    [event] = kept.events()
    assert event["exception"]["values"][-1]["type"] == "RuntimeError"
    assert event["tags"]["request_id"] == "trace-from-laravel-01"
    assert event["release"] == "zon-agents@test"
    request = event.get("request", {})
    assert "data" not in request and "cookies" not in request and not request.get("query_string")
    headers = {k.lower(): v for k, v in request.get("headers", {}).items()}
    assert headers.get("x-request-id") == "trace-from-laravel-01"
    frames = event["exception"]["values"][-1]["stacktrace"]["frames"]
    assert all("vars" not in frame for frame in frames), "no variable values"
    for frame in frames:  # the source lines around a frame are code — this test's own code holds the text
        for key in ("pre_context", "context_line", "post_context"):
            frame.pop(key, None)
    everything = json.dumps(event, ensure_ascii=False)
    for private in ("فيلا", "0551234567", INTERNAL_KEY):
        assert private not in everything, f"«{private}» left for Sentry"


def test_an_expected_answer_is_not_an_error(kept: Kept, client: TestClient) -> None:
    assert client.post("/agents/no_such_agent", json={}).status_code == 404
    assert client.post("/agents/search_listings", json={"need": {}, "candidates": []}).status_code == 422
    assert client.post("/agents/chat", json={}, headers={"X-Internal-Key": "wrong"}).status_code == 401

    sentry_sdk.flush()
    assert kept.events() == []


def test_without_a_dsn_nothing_starts(settings: Settings) -> None:
    assert monitoring.init(settings) is False
