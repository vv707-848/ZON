"""Before a hosted lab run (`zon-agents lab plan`, .github/workflows/lab.yml): which host the key is for, whether
it serves the model the way the service asks, and how much of the cases its limits allow in a day.

Nothing is assumed from a host's documentation: the model must be in the host's own list, and one small request is
made exactly as the service makes its own — a JSON schema, the host's switch that turns thinking off — and must
come back as that schema. A host that refuses says why, and the run stops before a case is spent.

The key is read from the environment and never printed; neither is anything derived from it but the host's name.
"""

import json
from dataclasses import dataclass, field
from typing import Any

import httpx

from zon_agents.lab.cases import Case


@dataclass(frozen=True)
class Host:
    name: str
    key_prefix: str
    base_url: str
    model: str  # Qwen3.8-27B as this host names it
    request: dict[str, Any]  # thinking off, in this host's words (and only a provider that honours the schema)
    per_minute: int  # cases a minute: under the host's request limit even when every answer is tried twice
    tokens_per_minute: int | None  # when the host's answer does not say its token limit
    daily_cases: int | None  # what a key with no paid plan gets through in a day; None: the host does not cap it


HOSTS = (
    # free plan (per model): 30 requests and 8,000 tokens a minute, 1,000 requests and 200,000 tokens a day
    Host(
        "Groq", "gsk_", "https://api.groq.com/openai/v1", "qwen/qwen3.8-27b", {"reasoning_effort": "none"}, 12, 6000, 60
    ),
    # a ":free" model: 20 requests a minute, 50 a day (1,000 once credits were bought)
    Host(
        "OpenRouter",
        "sk-or-",
        "https://openrouter.ai/api/v1",
        "qwen/qwen3.8-27b:free",
        {"reasoning": {"effort": "none"}, "provider": {"require_parameters": True}},
        9,
        None,
        40,
    ),
)

_PROBE = {
    "type": "json_schema",
    "json_schema": {
        "name": "probe",
        "strict": True,
        "schema": {
            "type": "object",
            "properties": {"city": {"type": "string"}, "rooms": {"type": "integer"}},
            "required": ["city", "rooms"],
            "additionalProperties": False,
        },
    },
}


class Refused(Exception):
    """The host cannot be measured as asked: the message says why, in words safe to print."""


@dataclass(frozen=True)
class Plan:
    host: Host
    model: str
    arguments: list[str]  # for `zon-agents lab run`
    note: str
    seen: list[str] = field(default_factory=list)  # what the host said, for the log


def host_of(key: str) -> Host:
    for host in HOSTS:
        if key.startswith(host.key_prefix):
            return host
    raise Refused("the key is not one of a host the lab knows (" + ", ".join(h.name for h in HOSTS) + ")")


def _message(response: httpx.Response) -> str:
    try:
        error = response.json().get("error")
    except ValueError:
        return response.text[:300]
    if not isinstance(error, dict):
        return str(error)[:300]
    # Groq says what the model wrote when it was not the schema (`failed_generation`): the reason is in it
    wrote = error.get("failed_generation")
    return str(error.get("message"))[:300] + (f" — the model wrote: {str(wrote)[:400]!r}" if wrote is not None else "")


def plan(
    key: str,
    cases: list[Case],
    *,
    client: httpx.Client,
    model: str = "",
    agents: str = "",
    per_agent: int | None = None,
    shift: int = 0,
) -> Plan:
    """[model]: another id at the host · [agents]: only these · [per_agent]: that many cases of each (0: all) ·
    [shift]: another day's sample (each pick moved that many cases on)."""
    host = host_of(key)
    model = model or host.model
    auth = {"Authorization": f"Bearer {key}"}
    seen = [f"host: {host.name} · model: {model}"]

    listed = client.get(f"{host.base_url}/models", headers=auth)
    if listed.status_code != 200:
        raise Refused(f"{host.name} did not list its models ({listed.status_code}): {_message(listed)}")
    ids = sorted(str(m.get("id")) for m in listed.json().get("data", []))
    if model not in ids:
        near = [i for i in ids if "qwen" in i.lower()]
        raise Refused(f"{host.name} does not serve {model}. Its Qwen models: {', '.join(near) or 'none'}")

    probe = client.post(
        f"{host.base_url}/chat/completions",
        headers=auth,
        json={
            "model": model,
            "messages": [{"role": "user", "content": "شقة في الرياض بثلاث غرف. أعد المدينة وعدد الغرف."}],
            "response_format": _PROBE,
            "max_completion_tokens": 256,
            "temperature": 0.3,
            **host.request,
        },
    )
    if probe.status_code != 200:
        raise Refused(
            f"{host.name} refused a request made as the service makes them ({probe.status_code}): {_message(probe)}"
        )
    message = probe.json()["choices"][0]["message"]
    try:
        answer = json.loads(message.get("content") or "")
        fits = isinstance(answer, dict) and set(answer) == {"city", "rooms"} and isinstance(answer["rooms"], int)
    except ValueError:
        fits = False
    if not fits:
        raise Refused(
            f"{model} at {host.name} did not answer in the schema it was given: {str(message.get('content'))[:200]!r}"
        )
    thought = message.get("reasoning") or message.get("reasoning_content")
    seen.append(f"probe: answered in the schema · thinking {'ON — its switch was ignored' if thought else 'off'}")
    limits = {k: v for k, v in probe.headers.items() if k.lower().startswith("x-ratelimit-")}
    if limits:
        seen.append("limits: " + " · ".join(f"{k.removeprefix('x-ratelimit-')} {v}" for k, v in sorted(limits.items())))

    tokens_per_minute = host.tokens_per_minute
    said = probe.headers.get("x-ratelimit-limit-tokens", "")
    if said.isdigit():
        tokens_per_minute = int(said) * 3 // 4  # a quarter kept back: the count is the host's, not ours
    arguments = ["--base-url", host.base_url, "--model", model, "--hosted", host.name]
    arguments += ["--request", json.dumps(host.request, separators=(",", ":")), "--rpm", str(host.per_minute)]
    if tokens_per_minute:
        arguments += ["--tpm", str(tokens_per_minute)]

    chosen = [c for c in cases if not agents or c.agent in set(agents.split(","))]
    if agents:
        arguments += ["--agents", agents]
    why = "as asked"
    if per_agent is None and host.daily_cases is not None and len(chosen) > host.daily_cases:
        per_agent = max(1, host.daily_cases // len({c.agent for c in chosen}))
        why = f"what {host.name}'s daily limit allows"
    if per_agent:
        arguments += ["--sample", str(per_agent), "--no-variants"]
        if shift:
            arguments += ["--sample-shift", str(shift)]
        note = f"{per_agent} cases of each agent{f', shifted by {shift}' if shift else ''}, no variants: {why}"
    else:
        note = "every case and its variants"
    seen.append(f"plan: {note}")
    return Plan(host, model, arguments, note, seen)
