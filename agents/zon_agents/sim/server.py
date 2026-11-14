"""The simulated AI node: the two servers of the real one on one port, answering with the rule model.

    GET  /v1/models · POST /v1/chat/completions    what the engine serves as `zon-ai` (OpenAI-compatible)
    POST /embed · GET /health                      the embedding server (deploy/ai-node/embed/server.py)
    GET  /sim/seen · POST /sim/mode · /sim/reset   the simulation's own: what reached it, and how it behaves

The agents service calls it unchanged (LLM_BASE_URL=http://<host>:<port>/v1 · EMBED_URL=http://<host>:<port> ·
LLM_PROFILE=sim-rules): the prompt, the schema, the retry and the checks are the production ones. An agent is
known by its prompt, as the engine would be told; its answer is `rules.answer` — or, by `/sim/mode`, what a failing
node does: `down` (503), `slow` (answers after `delay` seconds) or `invalid` (text that is not the schema).

`/sim/seen` returns what the node received, so a rehearsal can prove that no name and no number reached it.
It keeps the last 200 calls in memory and nothing else. It is for development and CI only: it has no key.
"""

import asyncio
import json
import time
from collections import deque
from dataclasses import dataclass, field
from typing import Any, Literal

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from zon_agents.llm import Prompt, load_prompts
from zon_agents.sim import rules
from zon_agents.sim.embed import bag_embedding
from zon_agents.sim.model import by_text

MODEL = "zon-ai"
KEPT = 200
Behaviour = Literal["ok", "down", "slow", "invalid"]


class Mode(BaseModel):
    llm: Behaviour = "ok"
    embed: Literal["ok", "down"] = "ok"
    delay: float = Field(default=0.0, ge=0, le=60)  # seconds a `slow` answer waits


@dataclass
class State:
    mode: Mode = field(default_factory=Mode)
    llm: deque[dict[str, Any]] = field(default_factory=lambda: deque(maxlen=KEPT))
    embed: deque[str] = field(default_factory=lambda: deque(maxlen=KEPT))


def _text(content: Any) -> str:
    """A message's text: a string, or the text parts of a list."""
    if isinstance(content, str):
        return content
    parts = content if isinstance(content, list) else []
    return "".join(str(p.get("text", "")) for p in parts if isinstance(p, dict))


def _unavailable(what: str) -> JSONResponse:
    return JSONResponse({"error": {"message": f"simulated: {what} is down", "type": "unavailable"}}, status_code=503)


def create_sim_app(prompts: dict[str, Prompt] | None = None) -> FastAPI:
    agents = by_text(prompts or load_prompts())
    state = State()
    app = FastAPI(title="zon-ai-sim", docs_url=None, redoc_url=None, openapi_url=None)

    @app.get("/v1/models")
    async def models() -> JSONResponse:
        if state.mode.llm == "down":
            return _unavailable("the model")
        return JSONResponse({"object": "list", "data": [{"id": MODEL, "object": "model", "owned_by": "zon-sim"}]})

    @app.post("/v1/chat/completions")
    async def completions(request: Request) -> JSONResponse:
        if state.mode.llm == "down":
            return _unavailable("the model")
        body = await request.json()
        messages = [m for m in body.get("messages", []) if isinstance(m, dict)]
        system = "\n".join(
            _text(m.get("content")) for m in messages if m.get("role") in ("system", "developer")
        ).strip()
        agent = agents.get(system)
        asked = next((_text(m.get("content")) for m in reversed(messages) if m.get("role") == "user"), "")
        try:
            payload = json.loads(asked)
        except ValueError:
            payload = None
        if agent is None or not isinstance(payload, dict):
            return JSONResponse(
                {"error": {"message": "simulated: not an agent's call", "type": "invalid"}}, status_code=400
            )
        state.llm.append({"agent": agent, "payload": payload})
        if state.mode.llm == "slow":
            await asyncio.sleep(state.mode.delay)
        content = (
            "عذراً، لا أستطيع."
            if state.mode.llm == "invalid"
            else json.dumps(rules.answer(agent, payload), ensure_ascii=False)
        )
        tokens_in, tokens_out = max(1, (len(system) + len(asked)) // 3), max(1, len(content) // 3)
        return JSONResponse(
            {
                "id": f"sim-{len(state.llm)}",
                "object": "chat.completion",
                "created": int(time.time()),
                "model": MODEL,
                "choices": [
                    {"index": 0, "message": {"role": "assistant", "content": content}, "finish_reason": "stop"}
                ],
                "usage": {
                    "prompt_tokens": tokens_in,
                    "completion_tokens": tokens_out,
                    "total_tokens": tokens_in + tokens_out,
                },
            }
        )

    @app.get("/health")
    async def health() -> JSONResponse:
        return _unavailable("the embedder") if state.mode.embed == "down" else JSONResponse({"status": "ok"})

    @app.post("/embed")
    async def embed(request: Request) -> JSONResponse:
        if state.mode.embed == "down":
            return _unavailable("the embedder")
        texts = [str(t) for t in (await request.json()).get("texts", [])]
        state.embed.extend(texts)
        rows = []
        for text in texts:
            e = bag_embedding(text)
            rows.append(
                {
                    "dense": e.dense,
                    "sparse": {"indices": e.sparse_indices, "values": e.sparse_values},
                    "colbert": e.colbert,
                }
            )
        return JSONResponse(rows)

    @app.get("/sim/seen")
    async def seen() -> dict[str, Any]:
        return {"llm": list(state.llm), "embed": list(state.embed), "mode": state.mode.model_dump()}

    @app.post("/sim/mode")
    async def mode(new: Mode) -> dict[str, Any]:
        state.mode = new
        return new.model_dump()

    @app.post("/sim/reset")
    async def reset() -> dict[str, Any]:
        state.mode = Mode()
        state.llm.clear()
        state.embed.clear()
        return {"status": "ok"}

    return app
