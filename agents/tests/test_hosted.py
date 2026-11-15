"""Before a hosted lab run (zon_agents/lab/hosted.py): the host is known from the key, its own list must hold the
model, and one request made as the service makes them must come back in its schema — else the run stops with the
host's reason, before a case is spent. What its limits allow in a day decides how many cases are asked."""

import json
from typing import Any

import httpx
import pytest

from zon_agents.lab import hosted
from zon_agents.lab.cases import load_cases

GROQ_KEY = "gsk_test0000000000000000"
ANSWER = {"choices": [{"message": {"role": "assistant", "content": '{"city": "الرياض", "rooms": 3}'}}]}


def _host(
    seen: list[httpx.Request],
    *,
    models: tuple[str, ...] = ("qwen/qwen3.8-27b", "qwen/qwen3.6-27b", "openai/gpt-oss-120b"),
    probe: httpx.Response | None = None,
) -> httpx.Client:
    def answer(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        if request.url.path.endswith("/models"):
            return httpx.Response(200, json={"data": [{"id": m} for m in models]})
        return probe or httpx.Response(200, json=ANSWER, headers={"x-ratelimit-limit-tokens": "8000"})

    return httpx.Client(transport=httpx.MockTransport(answer))


def _refusal(key: str, client: httpx.Client, **asked: Any) -> str:
    with pytest.raises(hosted.Refused) as refused:
        hosted.plan(key, load_cases(), client=client, **asked)
    return str(refused.value)


def test_groq_is_probed_as_the_service_asks_and_the_day_allows_a_sample_of_every_agent() -> None:
    seen: list[httpx.Request] = []
    plan = hosted.plan(GROQ_KEY, load_cases(), client=_host(seen))

    listed, probed = seen
    assert str(listed.url) == "https://api.groq.com/openai/v1/models"
    assert {r.headers["authorization"] for r in seen} == {f"Bearer {GROQ_KEY}"}
    body = json.loads(probed.content)
    assert (body["model"], body["reasoning_effort"]) == ("qwen/qwen3.8-27b", "none"), "thinking off, in Groq's words"
    assert body["response_format"]["type"] == "json_schema" and body["response_format"]["json_schema"]["strict"]

    assert plan.arguments == [
        *("--base-url", "https://api.groq.com/openai/v1", "--model", "qwen/qwen3.8-27b", "--hosted", "Groq"),
        *("--request", '{"reasoning_effort":"none"}', "--rpm", "12"),
        *("--tpm", "6000"),  # three quarters of the 8,000 its answer said
        *("--sample", "6", "--no-variants"),  # 60 cases a day over ten agents
    ]
    assert GROQ_KEY not in " ".join([*plan.arguments, *plan.seen, plan.note]), "the key is never printed"
    assert "thinking off" in plan.seen[1]


def test_asked_agents_that_fit_the_day_run_whole_and_a_sample_can_be_asked() -> None:
    whole = hosted.plan(GROQ_KEY, load_cases(), client=_host([]), agents="chat,summarize")
    assert whole.arguments[-2:] == ["--agents", "chat,summarize"] and whole.note == "every case and its variants"

    asked = hosted.plan(GROQ_KEY, load_cases(), client=_host([]), per_agent=2)
    assert asked.arguments[-3:] == ["--sample", "2", "--no-variants"]
    everything = hosted.plan(GROQ_KEY, load_cases(), client=_host([]), per_agent=0)
    assert "--sample" not in everything.arguments, "0: all of them, the owner's choice"


def test_openrouter_takes_its_own_switches_and_no_token_limit_of_ours() -> None:
    seen: list[httpx.Request] = []
    client = _host(seen, models=("qwen/qwen3.8-27b:free",), probe=httpx.Response(200, json=ANSWER))
    plan = hosted.plan("sk-or-v1-test", load_cases(), client=client)

    body = json.loads(seen[1].content)
    assert body["reasoning"] == {"effort": "none"} and body["provider"] == {"require_parameters": True}
    assert "--tpm" not in plan.arguments and plan.arguments[-3:] == ["--sample", "4", "--no-variants"]


def test_a_host_that_cannot_be_measured_says_why_before_a_case_is_spent() -> None:
    assert "not one of a host the lab knows" in _refusal("sk-unknown-000", _host([]))
    assert "sk-unknown-000" not in _refusal("sk-unknown-000", _host([]))

    seen: list[httpx.Request] = []
    gone = _refusal(GROQ_KEY, _host(seen, models=("qwen/qwen3.6-27b", "openai/gpt-oss-120b")))
    assert gone == "Groq does not serve qwen/qwen3.8-27b. Its Qwen models: qwen/qwen3.6-27b"
    assert len(seen) == 1, "no request to a model that is not there"

    no_schema = httpx.Response(
        400, json={"error": {"message": "This model does not support response format `json_schema`."}}
    )
    assert _refusal(GROQ_KEY, _host([], probe=no_schema)) == (
        "Groq refused a request made as the service makes them (400): "
        "This model does not support response format `json_schema`."
    )

    half = httpx.Response(
        400, json={"error": {"message": "Failed to generate JSON.", "failed_generation": '{"city": "الر'}}
    )
    assert _refusal(GROQ_KEY, _host([], probe=half)).endswith(
        'Failed to generate JSON. — the model wrote: \'{"city": "الر\''
    )

    prose = httpx.Response(200, json={"choices": [{"message": {"content": "المدينة الرياض وفيها ثلاث غرف"}}]})
    assert "did not answer in the schema" in _refusal(GROQ_KEY, _host([], probe=prose))


def test_thinking_left_on_is_said() -> None:
    thinking = {"choices": [{"message": {"content": '{"city": "الرياض", "rooms": 3}', "reasoning": "المستخدم يسأل…"}}]}
    plan = hosted.plan(GROQ_KEY, load_cases(), client=_host([], probe=httpx.Response(200, json=thinking)))
    assert "thinking ON — its switch was ignored" in plan.seen[1]
    assert plan.arguments[plan.arguments.index("--tpm") + 1] == "6000", "no header: the host's known limit"
