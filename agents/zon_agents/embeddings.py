"""Client of the bge-m3 embedding server (deploy/ai-node/embed/server.py), reached through the SSH tunnel.

`POST /embed {"texts": [...]}` returns, per text and in one pass: a dense vector (1024), sparse lexical weights
(token id → weight) and ColBERT vectors (one per token). Masking happens in Laravel BEFORE any text reaches this
client: the embedding server runs outside the Kingdom (architecture §10.1 — masking is step 6, embedding step 7).
"""

from collections.abc import Sequence
from dataclasses import dataclass
from typing import Any, Protocol

import httpx

from zon_agents.errors import DependencyUnavailable

DENSE_SIZE = 1024


@dataclass(frozen=True)
class Embedding:
    dense: list[float]
    sparse_indices: list[int]
    sparse_values: list[float]
    colbert: list[list[float]]


class Embedder(Protocol):
    async def embed(self, texts: Sequence[str]) -> list[Embedding]: ...

    async def healthy(self) -> bool: ...

    async def aclose(self) -> None: ...


def estimate_tokens(text: str) -> int:
    """bge-m3 (XLM-R tokenizer) makes roughly one token per three characters of Arabic or English."""
    return max(1, len(text) // 3)


class HttpEmbedder:
    def __init__(
        self,
        url: str,
        timeout_seconds: float,
        max_tokens_per_call: int,
        *,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._client = httpx.AsyncClient(base_url=url, timeout=timeout_seconds, transport=transport)
        self._max_tokens = max_tokens_per_call

    async def embed(self, texts: Sequence[str]) -> list[Embedding]:
        out: list[Embedding] = []
        for batch in self._batches(texts):
            try:
                response = await self._client.post("/embed", json={"texts": batch})
                response.raise_for_status()
                rows: list[dict[str, Any]] = response.json()
            except (httpx.HTTPError, ValueError) as exc:
                raise DependencyUnavailable("embed") from exc
            if len(rows) != len(batch):
                raise DependencyUnavailable("embed")
            out.extend(_parse(row) for row in rows)
        return out

    def _batches(self, texts: Sequence[str]) -> list[list[str]]:
        """Groups texts so one answer stays bounded (ColBERT returns a vector per token); a long text goes alone."""
        batches: list[list[str]] = []
        current: list[str] = []
        tokens = 0
        for text in texts:
            cost = estimate_tokens(text)
            if current and tokens + cost > self._max_tokens:
                batches.append(current)
                current, tokens = [], 0
            current.append(text)
            tokens += cost
        if current:
            batches.append(current)
        return batches

    async def healthy(self) -> bool:
        response = await self._client.get("/health", timeout=2.0)
        return response.status_code == 200 and response.json().get("status") == "ok"

    async def aclose(self) -> None:
        await self._client.aclose()


def _parse(row: dict[str, Any]) -> Embedding:
    sparse = row.get("sparse") or {}
    return Embedding(
        dense=[float(v) for v in row["dense"]],
        sparse_indices=[int(i) for i in sparse.get("indices", [])],
        sparse_values=[float(v) for v in sparse.get("values", [])],
        colbert=[[float(v) for v in vector] for vector in row.get("colbert", [])],
    )
