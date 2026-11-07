"""Index signals from Laravel's outbox → Qdrant (contract §4).

A signal carries the entity's version; the last indexed version of every `(entity, entity_id)` is kept here, so a
resent or out-of-order signal is ignored (`ignored_stale`) — resending is always safe. Only approved, published
text reaches the index: Laravel sends `delete` for anything that is not public.

One signal per entity at a time, from the version check to the saved version: Laravel gives up on a slow signal
(its HTTP timeout) and sends the next while the first may still wait on the embedder, and a delete must not be
undone by the update before it. The service runs as one process (`zon-agents serve`), so an asyncio lock per
entity is enough.
"""

import asyncio
import re
import sqlite3
import uuid
from pathlib import Path
from typing import Any

from pydantic import BaseModel, ValidationError

from zon_agents.embeddings import Embedder, estimate_tokens
from zon_agents.errors import InvalidPayload
from zon_agents.schemas.index import (
    IndexSignal,
    KbDocumentPayload,
    ListingPayload,
    ProjectUnitPayload,
    RequestPayload,
)
from zon_agents.store import KNOWLEDGE, LISTINGS, PROJECT_UNITS, REQUESTS, Point, VectorStore

COLLECTIONS = {"listing": LISTINGS, "project_unit": PROJECT_UNITS, "request": REQUESTS, "kb_document": KNOWLEDGE}
PAYLOADS: dict[str, type[BaseModel]] = {
    "listing": ListingPayload,
    "project_unit": ProjectUnitPayload,
    "request": RequestPayload,
    "kb_document": KbDocumentPayload,
}
KB_NAMESPACE = uuid.UUID("2b7c1d4e-8f3a-4c59-9e21-6a0d5b8c7f10")

# Passages of 400–600 tokens with a small overlap (architecture §10.5 «مكتبة المعرفة»).
CHUNK_TARGET_TOKENS = 500
CHUNK_MAX_TOKENS = 600
CHUNK_OVERLAP_TOKENS = 40
_SENTENCE_END = re.compile(r"(?<=[.!؟?\n])\s+")


def passage_id(doc_id: int, chunk_no: int) -> str:
    """The same id for the same passage of the same document: a new version overwrites it in place."""
    return str(uuid.uuid5(KB_NAMESPACE, f"{doc_id}:{chunk_no}"))


def chunk_text(body: str) -> list[str]:
    sentences = [s.strip() for s in _SENTENCE_END.split(body) if s.strip()]
    chunks: list[str] = []
    current: list[str] = []
    tokens = 0
    for sentence in sentences:
        cost = estimate_tokens(sentence)
        if current and tokens + cost > CHUNK_MAX_TOKENS:
            chunks.append(" ".join(current))
            overlap: list[str] = []
            while current and estimate_tokens(" ".join([current[-1], *overlap])) <= CHUNK_OVERLAP_TOKENS:
                overlap.insert(0, current.pop())
            current, tokens = overlap, estimate_tokens(" ".join(overlap)) if overlap else 0
        current.append(sentence)
        tokens += cost
        if tokens >= CHUNK_TARGET_TOKENS:
            chunks.append(" ".join(current))
            current, tokens = [], 0
    if current:
        chunks.append(" ".join(current))
    return chunks


class VersionStore:
    """Last indexed version per entity, in a small SQLite file next to the service (INDEX_STATE_PATH)."""

    def __init__(self, path: Path) -> None:
        if str(path) != ":memory:":
            path.parent.mkdir(parents=True, exist_ok=True)
        self._db = sqlite3.connect(str(path), check_same_thread=False)
        self._db.execute(
            "CREATE TABLE IF NOT EXISTS versions (entity TEXT NOT NULL, entity_id INTEGER NOT NULL,"
            " version INTEGER NOT NULL, PRIMARY KEY (entity, entity_id))"
        )
        self._lock = asyncio.Lock()

    async def last(self, entity: str, entity_id: int) -> int | None:
        async with self._lock:
            row = self._db.execute(
                "SELECT version FROM versions WHERE entity = ? AND entity_id = ?", (entity, entity_id)
            ).fetchone()
        return None if row is None else int(row[0])

    async def save(self, entity: str, entity_id: int, version: int) -> None:
        async with self._lock:
            self._db.execute(
                "INSERT INTO versions (entity, entity_id, version) VALUES (?, ?, ?)"
                " ON CONFLICT (entity, entity_id) DO UPDATE SET version = max(version, excluded.version)",
                (entity, entity_id, version),
            )
            self._db.commit()

    def close(self) -> None:
        self._db.close()


class Indexer:
    def __init__(self, embedder: Embedder, store: VectorStore, versions: VersionStore) -> None:
        self._embedder = embedder
        self._store = store
        self._versions = versions
        self._locks: dict[tuple[str, int], tuple[asyncio.Lock, int]] = {}  # entity → its lock, and who holds or waits

    async def handle(self, signal: IndexSignal) -> str:
        key = (signal.entity, signal.entity_id)
        lock, users = self._locks.get(key, (asyncio.Lock(), 0))
        self._locks[key] = (lock, users + 1)
        try:
            async with lock:
                return await self._handle(signal)
        finally:
            lock, users = self._locks[key]
            if users == 1:
                del self._locks[key]  # nobody waits: the entity's lock goes
            else:
                self._locks[key] = (lock, users - 1)

    async def _handle(self, signal: IndexSignal) -> str:
        last = await self._versions.last(signal.entity, signal.entity_id)
        if last is not None and signal.version <= last:
            return "ignored_stale"
        if signal.action == "delete":
            await self._delete(signal)
            status = "deleted"
        else:
            await self._index(signal, self._payload(signal))
            status = "indexed"
        await self._versions.save(signal.entity, signal.entity_id, signal.version)
        return status

    @staticmethod
    def _payload(signal: IndexSignal) -> BaseModel:
        try:
            return PAYLOADS[signal.entity].model_validate(signal.payload or {})
        except ValidationError as exc:
            raise InvalidPayload([f"payload.{'.'.join(str(p) for p in e['loc'])}" for e in exc.errors()][:10]) from exc

    async def _delete(self, signal: IndexSignal) -> None:
        if signal.entity == "kb_document":
            await self._store.delete_document(signal.entity_id)
        else:
            await self._store.delete_points(COLLECTIONS[signal.entity], [signal.entity_id])

    async def _index(self, signal: IndexSignal, payload: BaseModel) -> None:
        if isinstance(payload, KbDocumentPayload):
            await self._index_document(signal.entity_id, payload)
            return
        text, meta = _text_and_meta(payload)
        [embedding] = await self._embedder.embed([text])
        await self._store.upsert(COLLECTIONS[signal.entity], [Point(signal.entity_id, embedding, meta)])

    async def _index_document(self, doc_id: int, doc: KbDocumentPayload) -> None:
        """Passages carry their document's metadata. Passages left over from a longer previous body are removed after
        the new ones are written — the document is never missing from the index in between."""
        chunks = chunk_text(doc.body)
        if not chunks:
            raise InvalidPayload(["payload.body"])
        embeddings = await self._embedder.embed(chunks)
        meta = doc.model_dump(mode="json", exclude={"body"})
        points = [
            Point(passage_id(doc_id, n), embedding, {**meta, "doc_id": doc_id, "chunk_no": n, "text": chunk})
            for n, (chunk, embedding) in enumerate(zip(chunks, embeddings, strict=True))
        ]
        await self._store.upsert(KNOWLEDGE, points)
        await self._store.delete_document(doc_id, from_chunk=len(chunks))


def _text_and_meta(payload: BaseModel) -> tuple[str, dict[str, Any]]:
    """The approved text that is embedded, and the filter fields kept as payload (never a display source)."""
    if isinstance(payload, ListingPayload):
        return payload.desc_clean, payload.model_dump(exclude={"desc_clean"})
    if isinstance(payload, ProjectUnitPayload):
        return payload.desc_clean, payload.model_dump(exclude={"desc_clean"})
    if isinstance(payload, RequestPayload):
        summary = f"طلب {' أو '.join(payload.property_types)} في {'، '.join(payload.neighborhoods)} — {payload.city}"
        return f"{summary}. {payload.desc}".strip(" ."), payload.model_dump(exclude={"desc"})
    raise InvalidPayload(["payload"])  # pragma: no cover — every entity is handled above
