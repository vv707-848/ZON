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
from zon_agents.profiles import SIMULATED


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
