"""Retrieval: the need's text as three vectors, then Qdrant inside the candidates (architecture §10.1 steps 7–8).

Dense and ColBERT come from the user's own words; the sparse vector also gets the local-terms expansion that
Laravel sent (`terms` — «حوش» → «فناء»), because literal matching is where the expansion pays (§9).
"""

from collections.abc import Sequence
from dataclasses import replace
from datetime import date
from typing import Protocol

from zon_agents.embeddings import Embedder, Embedding
from zon_agents.store import Hit, KnowledgeChunk, VectorStore


class Retriever(Protocol):
    """What the pipelines need from retrieval — Qdrant here, a fixed order in the model lab."""

    async def query(self, text: str, terms: Sequence[str] = ()) -> Embedding: ...

    async def rank(self, collection: str, query: Embedding, ids: Sequence[int], limit: int) -> list[Hit]: ...

    async def knowledge(self, question: str, today: date) -> list[KnowledgeChunk]: ...


class Retrieval:
    def __init__(self, embedder: Embedder, store: VectorStore, top_k: int, min_score: float) -> None:
        self._embedder = embedder
        self._store = store
        self._top_k = top_k
        self._min_score = min_score

    async def query(self, text: str, terms: Sequence[str] = ()) -> Embedding:
        texts = [text, f"{text} {' '.join(terms)}"] if terms else [text]
        embeddings = await self._embedder.embed(texts)
        if len(embeddings) == 1:
            return embeddings[0]
        plain, expanded = embeddings
        return replace(plain, sparse_indices=expanded.sparse_indices, sparse_values=expanded.sparse_values)

    async def rank(self, collection: str, query: Embedding, ids: Sequence[int], limit: int) -> list[Hit]:
        return await self._store.hybrid(collection, query, ids, limit)

    async def knowledge(self, question: str, today: date) -> list[KnowledgeChunk]:
        query = await self.query(question)
        return await self._store.search_knowledge(query, limit=self._top_k, min_score=self._min_score, today=today)
