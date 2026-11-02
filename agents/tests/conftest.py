"""Test doubles: a deterministic embedder, a scripted model per agent, and Qdrant in memory (local mode).

Nothing here talks to a network: the model is PydanticAI's FunctionModel answering what each test scripts.
"""

import asyncio
import hashlib
import hmac
import json
import math
import re
import time
from collections import Counter
from collections.abc import Callable, Iterator, Sequence
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient
from pydantic_ai.messages import ModelMessage, ModelRequest, ModelResponse, TextPart, ToolCallPart, UserPromptPart
from pydantic_ai.models.function import AgentInfo, FunctionModel
from qdrant_client import AsyncQdrantClient

from zon_agents.config import Settings
from zon_agents.embeddings import DENSE_SIZE, Embedding
from zon_agents.errors import DependencyUnavailable
from zon_agents.indexing import VersionStore
from zon_agents.llm import LLM, load_prompts
from zon_agents.main import create_app
from zon_agents.services import Services
from zon_agents.store import VectorStore

INTERNAL_KEY = "test-internal-key"
INDEX_SECRET = "test-index-secret"
_WORD = re.compile(r"\w+")


def _slot(token: str) -> int:
    return int(hashlib.md5(token.encode()).hexdigest(), 16)


class FakeEmbedder:
    """Bag-of-words vectors: texts sharing words are close, so the hybrid order is predictable."""

    def __init__(self) -> None:
        self.down = False
        self.calls: list[list[str]] = []

    async def embed(self, texts: Sequence[str]) -> list[Embedding]:
        if self.down:
            raise DependencyUnavailable("embed")
        self.calls.append(list(texts))
        return [self._one(text) for text in texts]

    @staticmethod
    def _one(text: str) -> Embedding:
        tokens = _WORD.findall(text.lower()) or ["empty"]
        dense = [0.0] * DENSE_SIZE
        for token in tokens:
            dense[_slot(token) % DENSE_SIZE] += 1.0
        norm = math.sqrt(sum(v * v for v in dense))
        counts = Counter(_slot(token) % 250_000 for token in tokens)
        colbert = []
        for token in tokens[:24]:
            vector = [0.0] * DENSE_SIZE
            vector[_slot(token) % DENSE_SIZE] = 1.0
            colbert.append(vector)
        return Embedding([v / norm for v in dense], list(counts), [float(c) for c in counts.values()], colbert)

    async def healthy(self) -> bool:
        return not self.down

    async def aclose(self) -> None:
        return None


Answer = dict[str, Any] | Exception | Callable[[dict[str, Any]], dict[str, Any] | Exception]


class ScriptedModel:
    """Answers each agent with what the test put in `answers[agent]` (a dict, an exception, or a function of the
    payload, called once per attempt). Every call is kept in `calls` as (agent, payload, temperature)."""

    def __init__(self) -> None:
        self.prompts = load_prompts()
        self._agents = {prompt.text: name for name, prompt in self.prompts.items()}
        self.answers: dict[str, Answer | list[Answer]] = {}
        self.calls: list[tuple[str, dict[str, Any], float | None]] = []

    def __call__(self, messages: list[ModelMessage], info: AgentInfo) -> ModelResponse:
        request = next(m for m in messages if isinstance(m, ModelRequest))
        agent = self._agents[request.instructions or ""]
        prompt = next(p for p in request.parts if isinstance(p, UserPromptPart))
        payload = json.loads(str(prompt.content))
        temperature = (info.model_settings or {}).get("temperature")
        self.calls.append((agent, payload, temperature))
        scripted = self.answers[agent]
        if isinstance(scripted, list):  # one answer per attempt
            scripted = scripted.pop(0)
        result = scripted(payload) if callable(scripted) and not isinstance(scripted, Exception) else scripted
        if isinstance(result, Exception):
            raise result
        if info.output_tools:
            return ModelResponse(parts=[ToolCallPart(info.output_tools[0].name, result)])
        return ModelResponse(parts=[TextPart(json.dumps(result, ensure_ascii=False))])

    def calls_of(self, agent: str) -> list[tuple[str, dict[str, Any], float | None]]:
        return [call for call in self.calls if call[0] == agent]


@pytest.fixture
def model() -> ScriptedModel:
    return ScriptedModel()


@pytest.fixture
def embedder() -> FakeEmbedder:
    return FakeEmbedder()


@pytest.fixture
def services(model: ScriptedModel, embedder: FakeEmbedder) -> Services:
    store = VectorStore(AsyncQdrantClient(location=":memory:"), colbert=True, turbo_bits="bits4", prefetch_limit=100)
    asyncio.run(store.bootstrap())
    llm = LLM(FunctionModel(model), model.prompts, max_concurrency=8, budget_seconds=5)
    return Services(llm, embedder, store, VersionStore(Path(":memory:")), hasher=None)


@pytest.fixture
def settings() -> Settings:
    # The bag-of-words embedder gives lower similarities than bge-m3: the coverage threshold is set to match it.
    return Settings(
        agents_internal_key=INTERNAL_KEY,
        index_shared_secret=INDEX_SECRET,
        llm_api_key="unused",
        knowledge_min_score=0.3,
        health_cache_seconds=0,
    )


@pytest.fixture
def client(settings: Settings, services: Services) -> Iterator[TestClient]:
    with TestClient(create_app(settings, services), base_url="http://127.0.0.1:8100") as test_client:
        test_client.headers["X-Internal-Key"] = INTERNAL_KEY
        yield test_client


def call(client: TestClient, agent: str, body: dict[str, Any]) -> dict[str, Any]:
    response = client.post(f"/agents/{agent}", json=body)
    assert response.status_code == 200, response.text
    return dict(response.json())


def sign(body: bytes, timestamp: int | None = None, secret: str = INDEX_SECRET) -> dict[str, str]:
    stamp = str(timestamp if timestamp is not None else int(time.time()))
    digest = hmac.new(secret.encode(), stamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    return {"X-Timestamp": stamp, "X-Signature": f"sha256={digest}", "Content-Type": "application/json"}


def index(client: TestClient, entity: str, entity_id: int, payload: dict[str, Any] | None, version: int = 1) -> str:
    action = "delete" if payload is None else ("create" if version == 1 else "update")
    signal = {"entity": entity, "entity_id": entity_id, "action": action, "version": version, "payload": payload}
    body = json.dumps(signal, ensure_ascii=False).encode()
    response = client.post(f"/index/{entity}", content=body, headers=sign(body))
    assert response.status_code == 200, response.text
    return str(response.json()["status"])


def listing(neighborhood: str = "النرجس", desc: str = "شقة واسعة", **fields: Any) -> dict[str, Any]:
    return {"city": "الرياض", "neighborhood": neighborhood, "property_type": "apartment", "desc_clean": desc, **fields}
