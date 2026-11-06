"""Laravel ↔ agents: every payload the Laravel test suite sent is accepted by the agents' own input models.

The samples come from the backend: `ZON_CONTRACT_DIR=../docs/contracts/fixtures php artisan test`
(tests/TestCase.php keeps up to five distinct payloads per agent and index entity). The models ignore unknown
keys (Payload: extra="ignore"), so this test also fails on a key the agents would silently drop — the usual
sign of a key renamed on one side only.
"""

import json
from pathlib import Path
from typing import Any

import pytest
from pydantic import BaseModel

from zon_agents.indexing import PAYLOADS
from zon_agents.pipelines import PIPELINES
from zon_agents.schemas.agents import (
    BatchInput,
    ChatInput,
    CleanListingInput,
    CleanProjectInput,
    CleanUnitsInput,
    MatchingInput,
    MatchSearchersInput,
    ReportExploreInput,
    ReportSelectInput,
    ReportWriteInput,
    SearchListingsInput,
    SearchRequestsInput,
    SummarizeInput,
)
from zon_agents.schemas.index import IndexSignal

FIXTURES = Path(__file__).resolve().parents[2] / "docs" / "contracts" / "fixtures"

INPUTS: dict[str, type[BaseModel]] = {
    "search_listings": SearchListingsInput,
    "search_requests": SearchRequestsInput,
    "matching": MatchingInput,
    "match_searchers": MatchSearchersInput,
    "chat": ChatInput,
    "summarize": SummarizeInput,
    "clean_listing": CleanListingInput,
    "clean_project": CleanProjectInput,
    "clean_units": CleanUnitsInput,
    "report_select": ReportSelectInput,
    "report_explore": ReportExploreInput,
    "report_write": ReportWriteInput,
    "summarize_batch": BatchInput,
}


def samples(kind: str) -> list[tuple[str, dict[str, Any]]]:
    found = []
    for path in sorted((FIXTURES / kind).glob("*.json")):
        for i, payload in enumerate(json.loads(path.read_text(encoding="utf-8"))):
            found.append((f"{path.stem}#{i}", payload))
    return found


def dropped_keys(model: BaseModel, data: Any, at: str = "") -> list[str]:
    """Keys in `data` that `model` has no field for, at any depth of nested models."""
    if not isinstance(data, dict):
        return []
    fields = type(model).model_fields
    missing = [f"{at}{key}" for key in data if key not in fields]
    for name in fields:
        value, raw = getattr(model, name), data.get(name)
        if isinstance(value, BaseModel):
            missing += dropped_keys(value, raw, f"{at}{name}.")
        elif isinstance(value, list) and isinstance(raw, list):
            for i, (item, raw_item) in enumerate(zip(value, raw, strict=False)):
                if isinstance(item, BaseModel):
                    missing += dropped_keys(item, raw_item, f"{at}{name}[{i}].")
    return missing


pytestmark = pytest.mark.skipif(
    not FIXTURES.is_dir(), reason="no contract samples (run the Laravel suite with ZON_CONTRACT_DIR)"
)


def test_the_samples_cover_every_agent_and_every_index_entity() -> None:
    assert set(INPUTS) == set(PIPELINES)
    assert {name.split("#")[0] for name, _ in samples("agents")} == set(PIPELINES)
    assert {name.split("#")[0] for name, _ in samples("index")} == set(PAYLOADS)


@pytest.mark.parametrize(("name", "payload"), samples("agents") if FIXTURES.is_dir() else [])
def test_every_agent_payload_is_accepted_whole(name: str, payload: dict[str, Any]) -> None:
    model = INPUTS[name.split("#")[0]].model_validate(payload)
    assert dropped_keys(model, payload) == []


@pytest.mark.parametrize(("name", "signal"), samples("index") if FIXTURES.is_dir() else [])
def test_every_index_signal_and_its_payload_are_accepted_whole(name: str, signal: dict[str, Any]) -> None:
    parsed = IndexSignal.model_validate(signal)
    assert parsed.entity == name.split("#")[0]
    assert dropped_keys(parsed, signal) == []
    if parsed.action == "delete":
        assert parsed.payload is None
        return
    payload = PAYLOADS[parsed.entity].model_validate(parsed.payload)
    assert dropped_keys(payload, parsed.payload) == []
