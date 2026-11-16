"""Index signals: signature, versions, deletes and knowledge passages."""

import asyncio
import json
import time
from typing import Any

import pytest
from fastapi.testclient import TestClient
from pydantic import BaseModel

from tests.conftest import FakeEmbedder, index, listing, sign
from zon_agents.embeddings import estimate_tokens
from zon_agents.indexing import CHUNK_MAX_TOKENS, Indexer, chunk_text
from zon_agents.schemas.index import IndexSignal
from zon_agents.services import Services
from zon_agents.store import KNOWLEDGE


def _post(client: TestClient, body: dict[str, object], headers: dict[str, str] | None = None) -> int:
    raw = json.dumps(body).encode()
    return client.post(f"/index/{body['entity']}", content=raw, headers=headers or sign(raw)).status_code


def test_a_signal_needs_a_fresh_valid_signature(client: TestClient) -> None:
    body = {"entity": "listing", "entity_id": 1, "action": "delete", "version": 1}
    raw = json.dumps(body).encode()

    assert client.post("/index/listing", content=raw, headers=sign(raw, secret="wrong")).status_code == 401
    assert client.post("/index/listing", content=raw, headers=sign(raw, int(time.time()) - 301)).status_code == 401
    assert client.post("/index/listing", content=raw, headers=sign(raw)).status_code == 200


def test_versions_make_resending_safe(client: TestClient) -> None:
    assert index(client, "listing", 7, listing(), version=2) == "indexed"
    assert index(client, "listing", 7, listing(desc="نسخة أقدم"), version=1) == "ignored_stale"
    assert index(client, "listing", 7, listing(), version=2) == "ignored_stale"
    assert index(client, "listing", 7, None, version=3) == "deleted"


def test_an_update_still_waiting_on_the_embedder_cannot_undo_a_later_delete(
    services: Services, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Laravel gives up on a slow signal after 10 s and sends the next one: both are in hand at once."""

    async def run() -> None:
        entered, release = asyncio.Event(), asyncio.Event()
        indexer = Indexer(services.embedder, services.store, services.versions)
        points: dict[int, Any] = {}

        async def slow_write(signal: IndexSignal, payload: BaseModel) -> None:
            entered.set()
            await release.wait()
            points[signal.entity_id] = payload

        async def delete(signal: IndexSignal) -> None:
            points.pop(signal.entity_id, None)

        monkeypatch.setattr(indexer, "_index", slow_write)
        monkeypatch.setattr(indexer, "_delete", delete)
        first = IndexSignal(entity="listing", entity_id=7, action="create", version=1, payload=listing())
        old = asyncio.create_task(indexer.handle(first))
        await entered.wait()
        new = asyncio.create_task(
            indexer.handle(first.model_copy(update={"action": "delete", "version": 2, "payload": None}))
        )
        await asyncio.sleep(0)
        release.set()

        assert await asyncio.gather(old, new) == ["indexed", "deleted"]
        assert points == {}, "the deleted listing came back"
        assert await services.versions.last("listing", 7) == 2

    asyncio.run(run())


def test_a_saved_version_never_goes_back(services: Services) -> None:
    async def run() -> None:
        await services.versions.save("listing", 8, 5)
        await services.versions.save("listing", 8, 3)
        assert await services.versions.last("listing", 8) == 5

    asyncio.run(run())


def test_a_bad_payload_or_a_path_that_disagrees_is_refused(client: TestClient) -> None:
    good = {"entity": "listing", "entity_id": 3, "action": "create", "version": 1, "payload": listing()}

    assert _post(client, {**good, "payload": {"city": "الرياض"}}) == 422
    raw = json.dumps(good).encode()
    assert client.post("/index/request", content=raw, headers=sign(raw)).status_code == 422


def test_while_embeddings_are_down_the_signal_fails_and_can_be_sent_again(
    client: TestClient, embedder: FakeEmbedder
) -> None:
    embedder.down = True
    body = {"entity": "listing", "entity_id": 9, "action": "create", "version": 1, "payload": listing()}
    assert _post(client, body) == 503

    embedder.down = False
    assert index(client, "listing", 9, listing()) == "indexed"


def test_a_document_is_cut_into_passages_that_carry_its_metadata(client: TestClient, services: Services) -> None:
    sentence = "يحق لغير السعودي تملك عقار سكني وفق ضوابط نظام تملك غير السعوديين للعقار. "
    document = {
        "title": "تملك غير السعوديين",
        "category": "ownership",
        "lang": "ar",
        "version": 2,
        "effective_date": "2026-05-01",
        "source": "الهيئة العامة للعقار",
        "review_owner": "فريق المحتوى",
        "next_review": "2099-01-01",
        "body": sentence * 120,
    }
    assert index(client, "kb_document", 12, document) == "indexed"
    points, _ = asyncio.run(services.store._client.scroll(KNOWLEDGE, limit=100))
    assert len(points) > 1
    assert {p.payload["review_owner"] for p in points if p.payload} == {"فريق المحتوى"}
    assert {p.payload["doc_id"] for p in points if p.payload} == {12}

    assert index(client, "kb_document", 12, {**document, "body": sentence}, version=2) == "indexed"
    points, _ = asyncio.run(services.store._client.scroll(KNOWLEDGE, limit=100))
    assert len(points) == 1  # the passages of the longer body are gone


def test_a_text_with_no_full_stop_is_cut_between_words_and_nothing_is_left_past_the_embedding_limit() -> None:
    clause = "ويستثنى من ذلك ما يصدر به قرار من مجلس الوزراء بشرط موافقة الجهة المختصة خلال المهلة النظامية"
    body = " ".join([clause] * 120) + " وآخر النص الشرط الحاكم"  # one «sentence» of some 3,800 tokens

    passages = chunk_text(body)

    assert len(passages) > 1
    assert all(estimate_tokens(p) <= CHUNK_MAX_TOKENS for p in passages), "each passage fits what the model embeds"
    assert passages[-1].endswith("الشرط الحاكم"), "the end of the text is in a passage"
    assert " ".join(passages).split() == body.split(), "nothing dropped, nothing cut inside a word"
