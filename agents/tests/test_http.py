"""The HTTP surface: authentication, errors, health, and the trace id."""

import logging

import pytest
from fastapi.testclient import TestClient

from tests.conftest import FakeEmbedder, ScriptedModel


def test_every_agent_route_needs_the_internal_key(client: TestClient) -> None:
    response = client.post("/agents/search_listings", json={}, headers={"X-Internal-Key": "wrong"})

    assert response.status_code == 401


def test_an_unknown_agent_and_an_invalid_input_are_told_apart(client: TestClient) -> None:
    assert client.post("/agents/no_such_agent", json={}).status_code == 404

    response = client.post("/agents/search_listings", json={"need": {"city": "الرياض"}, "candidates": []})

    assert response.status_code == 422
    assert "need.neighborhood" in response.json()["fields"]


def test_the_callers_trace_id_comes_back_and_is_on_the_log_line(
    client: TestClient, model: ScriptedModel, caplog: pytest.LogCaptureFixture
) -> None:
    model.answers["clean_project"] = {"desc_clean": "مشروع قريب من الخدمات"}
    caplog.set_level(logging.INFO, logger="zon_agents")

    response = client.post(
        "/agents/clean_project", json={"text": "مشروع سري جداً"}, headers={"X-Request-Id": "trace-0001-abc"}
    )

    assert response.headers["X-Request-Id"] == "trace-0001-abc"
    [line] = [r.fields for r in caplog.records if r.getMessage() == "request"]  # type: ignore[attr-defined]
    assert line["request_id"] == "trace-0001-abc"
    assert line["agent"] == "clean_project" and line["status"] == 200
    assert "سري" not in str(line)  # never the text of a request


def test_health_reports_each_dependency(client: TestClient, embedder: FakeEmbedder) -> None:
    model = "qwen3.6-35b-a3b-escha-w2"
    assert client.get("/agents/health").json() == {
        "status": "ok",
        "llm": True,
        "embed": True,
        "qdrant": True,
        "model": model,
        "errors": False,  # no SENTRY_DSN in the tests
    }

    embedder.down = True

    assert client.get("/agents/health").json() == {
        "status": "ok",
        "llm": True,
        "embed": False,
        "qdrant": True,
        "model": model,
        "errors": False,
    }


def test_image_hashing_says_when_it_is_not_installed(client: TestClient) -> None:
    response = client.post("/media/phash", content=b"\x89PNG")

    assert response.status_code == 501
