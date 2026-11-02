"""Qdrant collections and the hybrid query (architecture §9).

Four collections — listings · project_units · requests · knowledge — each with a dense vector (1024, cosine,
TurboQuant pinned in memory), a sparse vector, and ColBERT multivectors (MaxSim, hnsw m=0, on disk, int8) except
knowledge. A ranking query never searches a whole collection: it is restricted to the candidate ids Laravel built
in SQL. The payload is a filter, never a display source — Laravel reads what it shows from MySQL.
"""

from collections.abc import AsyncIterator, Sequence
from contextlib import asynccontextmanager
from dataclasses import dataclass
from datetime import date
from typing import Any

import httpx
from pydantic import BaseModel
from qdrant_client import AsyncQdrantClient, models
from qdrant_client.http.exceptions import ApiException

from zon_agents.embeddings import DENSE_SIZE, Embedding
from zon_agents.errors import DependencyUnavailable

DENSE = "dense"
SPARSE = "sparse"
COLBERT = "colbert"

LISTINGS = "listings"
PROJECT_UNITS = "project_units"
REQUESTS = "requests"
KNOWLEDGE = "knowledge"

# Payload fields used in filters, per collection, with their index type.
PAYLOAD_INDEXES: dict[str, dict[str, models.PayloadSchemaType]] = {
    LISTINGS: {
        "city": models.PayloadSchemaType.KEYWORD,
        "neighborhood": models.PayloadSchemaType.KEYWORD,
        "property_type": models.PayloadSchemaType.KEYWORD,
        "price": models.PayloadSchemaType.FLOAT,
    },
    PROJECT_UNITS: {
        "city": models.PayloadSchemaType.KEYWORD,
        "neighborhood": models.PayloadSchemaType.KEYWORD,
        "property_type": models.PayloadSchemaType.KEYWORD,
        "project_id": models.PayloadSchemaType.INTEGER,
        "price": models.PayloadSchemaType.FLOAT,
    },
    REQUESTS: {
        "city": models.PayloadSchemaType.KEYWORD,
        "neighborhoods": models.PayloadSchemaType.KEYWORD,
        "property_types": models.PayloadSchemaType.KEYWORD,
    },
    KNOWLEDGE: {
        "doc_id": models.PayloadSchemaType.INTEGER,
        "chunk_no": models.PayloadSchemaType.INTEGER,
        "category": models.PayloadSchemaType.KEYWORD,
        "lang": models.PayloadSchemaType.KEYWORD,
        "next_review": models.PayloadSchemaType.DATETIME,
    },
}


@dataclass(frozen=True)
class Hit:
    id: int
    score: float  # 0..1


class KnowledgeChunk(BaseModel):
    doc_id: int
    title: str
    category: str
    version: str
    effective_date: str
    source: str
    review_owner: str | None = None
    next_review: str
    lang: str
    chunk_no: int
    text: str


@dataclass(frozen=True)
class Point:
    id: int | str
    embedding: Embedding
    payload: dict[str, Any]


@asynccontextmanager
async def _guard() -> AsyncIterator[None]:
    try:
        yield
    except (ApiException, httpx.HTTPError, TimeoutError, ConnectionError) as exc:
        raise DependencyUnavailable("qdrant") from exc


class VectorStore:
    def __init__(self, client: AsyncQdrantClient, *, colbert: bool, turbo_bits: str, prefetch_limit: int) -> None:
        self._client = client
        self._colbert = colbert
        self._turbo_bits = models.TurboQuantBitSize(turbo_bits)
        self._prefetch = prefetch_limit

    def _uses_colbert(self, collection: str) -> bool:
        return self._colbert and collection != KNOWLEDGE

    # ── setup ────────────────────────────────────────────────────────────────────────────────────

    async def bootstrap(self, *, recreate: bool = False) -> list[str]:
        """Creates missing collections and their payload indexes; `recreate` drops them first (then reindex)."""
        created: list[str] = []
        async with _guard():
            for name, indexes in PAYLOAD_INDEXES.items():
                exists = await self._client.collection_exists(name)
                if exists and recreate:
                    await self._client.delete_collection(name)
                    exists = False
                if not exists:
                    await self._client.create_collection(
                        name,
                        vectors_config=self._vectors(name),
                        sparse_vectors_config={SPARSE: models.SparseVectorParams()},
                    )
                    created.append(name)
                for field, schema in indexes.items():
                    await self._client.create_payload_index(name, field, field_schema=schema)
        return created

    def _vectors(self, collection: str) -> dict[str, models.VectorParams]:
        vectors = {
            DENSE: models.VectorParams(
                size=DENSE_SIZE,
                distance=models.Distance.COSINE,
                quantization_config=models.TurboQuantization(
                    turbo=models.TurboQuantQuantizationConfig(bits=self._turbo_bits, memory=models.Memory.PINNED)
                ),
            )
        }
        if self._uses_colbert(collection):
            vectors[COLBERT] = models.VectorParams(
                size=DENSE_SIZE,
                distance=models.Distance.COSINE,
                multivector_config=models.MultiVectorConfig(comparator=models.MultiVectorComparator.MAX_SIM),
                hnsw_config=models.HnswConfigDiff(m=0),  # rerank only — no graph
                on_disk=True,
                quantization_config=models.ScalarQuantization(
                    scalar=models.ScalarQuantizationConfig(type=models.ScalarType.INT8, memory=models.Memory.COLD)
                ),
            )
        return vectors

    # ── writes ───────────────────────────────────────────────────────────────────────────────────

    async def upsert(self, collection: str, points: Sequence[Point]) -> None:
        structs = [
            models.PointStruct(id=point.id, vector=self._vector(collection, point.embedding), payload=point.payload)
            for point in points
        ]
        async with _guard():
            await self._client.upsert(collection, points=structs, wait=True)

    def _vector(self, collection: str, embedding: Embedding) -> dict[str, Any]:
        vector: dict[str, Any] = {
            DENSE: embedding.dense,
            SPARSE: models.SparseVector(indices=embedding.sparse_indices, values=embedding.sparse_values),
        }
        if self._uses_colbert(collection):
            vector[COLBERT] = embedding.colbert
        return vector

    async def delete_points(self, collection: str, ids: Sequence[int]) -> None:
        async with _guard():
            await self._client.delete(collection, points_selector=models.PointIdsList(points=list(ids)), wait=True)

    async def delete_document(self, doc_id: int, *, from_chunk: int = 0) -> None:
        """Removes a knowledge document's passages, or only those numbered `from_chunk` and after."""
        conditions: list[models.Condition] = [
            models.FieldCondition(key="doc_id", match=models.MatchValue(value=doc_id))
        ]
        if from_chunk > 0:
            conditions.append(models.FieldCondition(key="chunk_no", range=models.Range(gte=from_chunk)))
        async with _guard():
            await self._client.delete(
                KNOWLEDGE, points_selector=models.FilterSelector(filter=models.Filter(must=conditions)), wait=True
            )

    # ── queries ──────────────────────────────────────────────────────────────────────────────────

    async def hybrid(self, collection: str, query: Embedding, ids: Sequence[int], limit: int) -> list[Hit]:
        """Dense + sparse inside the candidates, then a ColBERT rerank (or RRF fusion without ColBERT)."""
        if not ids:
            return []
        within = models.Filter(must=[models.HasIdCondition(has_id=list(ids))])
        prefetch = [
            models.Prefetch(query=query.dense, using=DENSE, filter=within, limit=self._prefetch),
            models.Prefetch(query=self._sparse(query), using=SPARSE, filter=within, limit=self._prefetch),
        ]
        async with _guard():
            if self._uses_colbert(collection) and query.colbert:
                result = await self._client.query_points(
                    collection, prefetch=prefetch, query=query.colbert, using=COLBERT, limit=limit, with_payload=False
                )
                tokens = len(query.colbert)
                return [Hit(int(p.id), _unit(p.score / tokens)) for p in result.points]
            result = await self._client.query_points(
                collection, prefetch=prefetch, query=models.FusionQuery(fusion=models.Fusion.RRF), limit=limit
            )
        top = result.points[0].score if result.points else 1.0
        return [Hit(int(p.id), _unit(p.score / top if top else 0.0)) for p in result.points]

    async def search_knowledge(
        self, query: Embedding, *, limit: int, min_score: float, today: date
    ) -> list[KnowledgeChunk]:
        """Passages of documents not overdue for review. Coverage is decided by dense similarity alone: under
        `min_score` nothing is returned (the chat then says it is not covered, without calling the model)."""
        current = models.Filter(
            must=[models.FieldCondition(key="next_review", range=models.DatetimeRange(gte=today.isoformat()))]
        )
        async with _guard():
            dense = await self._client.query_points(
                KNOWLEDGE,
                query=query.dense,
                using=DENSE,
                query_filter=current,
                limit=limit * 4,
                score_threshold=min_score,
            )
            if not dense.points:
                return []
            within = models.Filter(must=[models.HasIdCondition(has_id=[p.id for p in dense.points])])
            fused = await self._client.query_points(
                KNOWLEDGE,
                prefetch=[
                    models.Prefetch(query=query.dense, using=DENSE, filter=within, limit=len(dense.points)),
                    models.Prefetch(query=self._sparse(query), using=SPARSE, filter=within, limit=len(dense.points)),
                ],
                query=models.FusionQuery(fusion=models.Fusion.RRF),
                limit=limit,
                with_payload=True,
            )
        return [KnowledgeChunk.model_validate(p.payload) for p in fused.points]

    @staticmethod
    def _sparse(query: Embedding) -> models.SparseVector:
        return models.SparseVector(indices=query.sparse_indices, values=query.sparse_values)

    async def healthy(self) -> bool:
        await self._client.get_collections()
        return True

    async def aclose(self) -> None:
        await self._client.close()


def _unit(score: float) -> float:
    return round(min(1.0, max(0.0, score)), 3)
