"""The model call (zon_agents/llm.py) over the real OpenAI client, against an endpoint that answers as an engine can.

An answer cut off at `max_tokens` (finish_reason «length») is half a JSON. PydanticAI 2.68 raises
`OutputTokenLimitExceeded` for it, a kind of `UnexpectedModelBehavior`: the call must try once more and then give
the service's fallback («invalid»), never a half answer and never an unhandled error.
"""

import asyncio
import json
from typing import Any

import httpx
import pytest
from pydantic import BaseModel

from zon_agents.llm import LLM, ModelFailed, load_prompts, openai_model
from zon_agents.pipelines.reports import _choices_model
from zon_agents.profiles import SIMULATED
from zon_agents.schemas.agents import (
    BatchAnswer,
    ChatAnswer,
    CleanListingAnswer,
    CleanProjectAnswer,
    CleanUnitsAnswer,
    MatchingAnswer,
    SearchAnswer,
    SearchersAnswer,
    SummaryAnswer,
)


class _Ranked(BaseModel):
    results: list[dict[str, Any]]


def _completion(content: str, finish: str) -> dict[str, Any]:
    return {
        "id": "cmpl-1",
        "object": "chat.completion",
        "created": 0,
        "model": "zon-ai",
        "choices": [{"index": 0, "finish_reason": finish, "message": {"role": "assistant", "content": content}}],
        "usage": {"prompt_tokens": 10, "completion_tokens": 700, "total_tokens": 710},
    }


def _llm(answers: list[dict[str, Any]], seen: list[dict[str, Any]]) -> LLM:
    def answer(request: httpx.Request) -> httpx.Response:
        seen.append(json.loads(request.content))
        return httpx.Response(200, json=answers[min(len(seen), len(answers)) - 1])

    client = httpx.AsyncClient(transport=httpx.MockTransport(answer), base_url="http://engine")
    model = openai_model("http://engine/v1", "unused", "zon-ai", 5, http_client=client)
    return LLM(model, load_prompts(), profile=SIMULATED, max_concurrency=1, budget_seconds=5)


def test_an_answer_cut_off_at_max_tokens_is_tried_once_more_then_falls_back() -> None:
    seen: list[dict[str, Any]] = []
    cut = _completion('{"results": [{"id": 3, "score": 0.9}, {"id"', "length")
    llm = _llm([cut, cut], seen)

    with pytest.raises(ModelFailed) as failed:
        asyncio.run(llm.run("search_listings", {"need": {}, "candidates": []}, _Ranked, "json"))

    assert failed.value.reason == "invalid"
    assert len(seen) == 2, "one more try, then the fallback"


def test_a_whole_answer_after_a_cut_one_is_used() -> None:
    seen: list[dict[str, Any]] = []
    cut = _completion('{"results": [{"id"', "length")
    whole = _completion('{"results": [{"id": 3, "score": 0.9}]}', "stop")
    llm = _llm([cut, whole], seen)

    result = asyncio.run(llm.run("search_listings", {"need": {}, "candidates": []}, _Ranked, "json"))

    assert result.output.results == [{"id": 3, "score": 0.9}]
    assert len(seen) == 2


def test_an_endpoint_that_refuses_is_unavailable_and_its_reason_is_kept() -> None:
    def refuse(request: httpx.Request) -> httpx.Response:
        return httpx.Response(429, json={"error": {"message": "Rate limit reached for tokens per minute."}})

    client = httpx.AsyncClient(transport=httpx.MockTransport(refuse), base_url="http://engine")
    model = openai_model("http://engine/v1", "unused", "zon-ai", 5, http_client=client)
    llm = LLM(model, load_prompts(), profile=SIMULATED, max_concurrency=1, budget_seconds=5)

    with pytest.raises(ModelFailed) as failed:
        asyncio.run(llm.run("search_listings", {"need": {}, "candidates": []}, _Ranked, "json"))

    assert failed.value.reason == "unavailable", "the endpoint's refusal, not the model's answer"
    ((reason, count),) = llm.refusals.items()
    assert count == 1 and "Rate limit reached for tokens per minute." in reason


class _Open(BaseModel):
    report: str
    params: dict[str, Any] = {}


def _asked(output: type[BaseModel], *, hosted: bool) -> dict[str, Any]:
    """The `response_format` one call sends."""
    seen: list[dict[str, Any]] = []

    def answer(request: httpx.Request) -> httpx.Response:
        seen.append(json.loads(request.content))
        return httpx.Response(503, json={"error": {"message": "not now"}})

    client = httpx.AsyncClient(transport=httpx.MockTransport(answer), base_url="http://engine")
    model = openai_model("http://engine/v1", "unused", "zon-ai", 5, http_client=client, strict_schemas=hosted)
    profile = SIMULATED.hosted_by("Groq", {}) if hosted else SIMULATED
    llm = LLM(model, load_prompts(), profile=profile, max_concurrency=1, budget_seconds=5)
    with pytest.raises(ModelFailed):
        asyncio.run(llm.run("search_listings", {}, output, "json"))
    return dict(seen[0]["response_format"]["json_schema"])


def test_a_host_gets_the_schema_in_its_strict_form_and_our_engines_get_it_as_it_was() -> None:
    ours = _asked(SearchersAnswer, hosted=False)
    row = ours["schema"]["properties"]["results"]["items"]
    assert "additionalProperties" not in row and row["required"] == ["id", "score"], "the node's request did not change"

    theirs = _asked(SearchersAnswer, hosted=True)
    row = theirs["schema"]["$defs"]["RankedSearcher"]
    assert theirs["strict"] is True
    assert theirs["schema"]["additionalProperties"] is False and row["additionalProperties"] is False
    assert row["required"] == ["id", "score", "unit_id"], "every key required: an absent one is written as null"

    catalog = {"top_demand": {"city": {"type": "string", "enum": ["جدة"]}, "days": {"type": "integer"}}}
    choices = _asked(_choices_model(catalog), hosted=True)
    assert choices["strict"] is True, "a report's parameters are named: a host can hold the model to them"
    assert choices["schema"]["$defs"]["Params"]["required"] == ["city", "days"]

    free = _asked(_Open, hosted=True)
    assert free["strict"] is False, "a free map cannot be said strictly: asked for without it"
    assert "params" in free["schema"]["properties"]


def test_no_answer_a_host_is_asked_for_holds_a_const_its_strict_mode_refuses() -> None:
    answers = [ChatAnswer, SearchAnswer, MatchingAnswer, SearchersAnswer, SummaryAnswer, BatchAnswer]
    answers += [CleanListingAnswer, CleanProjectAnswer, CleanUnitsAnswer]
    for output in answers:
        strict = json.dumps(_asked(output, hosted=True)["schema"])
        assert '"const"' not in strict, f"{output.__name__}: a one-value choice is an enum, not a const"


def test_a_host_that_says_the_model_wrote_no_schema_is_the_models_failure_not_the_endpoints() -> None:
    seen: list[dict[str, Any]] = []

    def answer(request: httpx.Request) -> httpx.Response:
        seen.append(json.loads(request.content))
        error = {"message": "Failed to generate JSON.", "code": "json_validate_failed", "failed_generation": "{"}
        return httpx.Response(400, json={"error": error})

    client = httpx.AsyncClient(transport=httpx.MockTransport(answer), base_url="http://engine")
    model = openai_model("http://engine/v1", "unused", "zon-ai", 5, http_client=client)
    llm = LLM(model, load_prompts(), profile=SIMULATED, max_concurrency=1, budget_seconds=5)

    with pytest.raises(ModelFailed) as failed:
        asyncio.run(llm.run("search_listings", {"need": {}, "candidates": []}, _Ranked, "json"))

    assert failed.value.reason == "invalid" and len(seen) == 2, "tried once more, as any answer off the schema"
    assert not llm.refusals
