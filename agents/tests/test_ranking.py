"""Ranking agents: candidates only, reasons of twelve words, and the degraded answer."""

import httpx
from fastapi.testclient import TestClient

from tests.conftest import FakeEmbedder, ScriptedModel, call, index, listing

NEED = {"city": "الرياض", "neighborhood": "النرجس", "property_type": "apartment", "budget_max": 950000}


def _candidates(*ids: int) -> list[dict[str, object]]:
    return [
        {"id": i, "type": "apartment", "neighborhood": "النرجس", "price": 800000 + i, "desc": f"عرض {i}"} for i in ids
    ]


def _indexed(client: TestClient) -> None:
    index(client, "listing", 1, listing(desc="شقة دور أرضي بمدخلين وحوش"))
    index(client, "listing", 2, listing(desc="شقة في الدور الثالث مع مصعد"))
    index(client, "listing", 3, listing(desc="شقة دور أول قريبة من المدارس"))


def test_the_model_orders_the_candidates_and_nothing_else_comes_through(
    client: TestClient, model: ScriptedModel
) -> None:
    _indexed(client)
    model.answers["search_listings"] = {
        "results": [
            {"id": 3, "score": 0.9, "reason": "قريبة من المدارس كما طلبت وضمن الميزانية تماماً بلا أي مشكلة أبداً والله"},
            {"id": 99, "score": 0.8, "reason": "عرض مختلق"},
            {"id": 1, "score": 0.7, "reason": "دور أرضي — اتصل 0551234567"},
            {"id": 3, "score": 0.6, "reason": "تكرار"},
        ],
        "query_understood": "شقة · النرجس",
    }

    body = call(
        client, "search_listings", {"need": {**NEED, "free_text": "دور أرضي"}, "candidates": _candidates(1, 2, 3)}
    )

    results = body["output"]["results"]
    assert [r["id"] for r in results] == [3, 1]
    assert len(results[0]["reason"].split()) == 12
    assert "0551234567" not in results[1]["reason"]
    assert body["degraded"] is False


def test_without_the_model_the_hybrid_order_answers_without_reasons(client: TestClient, model: ScriptedModel) -> None:
    _indexed(client)
    model.answers["search_listings"] = httpx.ConnectError("tunnel down")

    body = call(
        client,
        "search_listings",
        {"need": {**NEED, "free_text": "دور أرضي بمدخلين"}, "candidates": _candidates(1, 2, 3)},
    )

    assert body["degraded"] is True
    assert body["output"]["results"][0]["id"] == 1  # the description that shares the words comes first
    assert {r["reason"] for r in body["output"]["results"]} == {""}


def test_a_broken_answer_is_tried_once_more_at_a_low_temperature(client: TestClient, model: ScriptedModel) -> None:
    _indexed(client)
    model.answers["search_listings"] = [{"results": "not a list"}, {"results": [{"id": 2, "score": 0.5}]}]

    body = call(client, "search_listings", {"need": NEED, "candidates": _candidates(1, 2)})

    assert [r["id"] for r in body["output"]["results"]] == [2]
    assert [temperature for *_, temperature in model.calls_of("search_listings")] == [0.3, 0.1]


def test_when_the_embedding_server_is_down_laravel_takes_its_fallback(
    client: TestClient, embedder: FakeEmbedder
) -> None:
    embedder.down = True

    response = client.post(
        "/agents/search_listings", json={"need": {**NEED, "free_text": "حوش"}, "candidates": _candidates(1)}
    )

    assert response.status_code == 503


def test_matching_keeps_two_separate_lists_and_ranks_units_without_reasons(
    client: TestClient, model: ScriptedModel
) -> None:
    _indexed(client)
    unit = {"project_id": 5, "city": "الرياض", "neighborhood": "النرجس", "property_type": "apartment", "model": "C1"}
    index(client, "project_unit", 40, {**unit, "desc_clean": "نموذج بحوش ومدخل خاص"})
    model.answers["matching"] = {
        "matched": [
            {"id": 1, "score": 0.9, "reason": "دور أرضي بمدخلين"},
            {"id": 2, "score": 0.4, "reason": "من القائمة الأخرى"},
        ],
        "broaden": [{"id": 2, "score": 0.6, "reason": "نوع آخر ضمن ميزانيتك"}],
    }
    payload = {
        "need": {**NEED, "free_text": "حوش ومدخل"},
        "matched": _candidates(1),
        "broaden": _candidates(2),
        "units": [{"id": 40, "model": "C1", "project_id": 5}],
    }

    output = call(client, "matching", payload)["output"]

    assert [r["id"] for r in output["matched"]] == [1]
    assert [r["id"] for r in output["broaden"]] == [2]
    assert output["units"][0]["id"] == 40 and "reason" not in output["units"][0]


def test_no_candidate_means_no_model_call(client: TestClient, model: ScriptedModel) -> None:
    output = call(client, "matching", {"need": NEED})["output"]

    assert output == {"matched": [], "broaden": [], "units": []}
    assert model.calls == []


def test_the_developer_gets_searchers_with_one_of_his_own_unit_models(client: TestClient, model: ScriptedModel) -> None:
    units = [
        {"id": 40, "model": "C1", "type": "apartment", "price": 800000},
        {"id": 41, "model": "B2", "price": 1200000},
    ]
    searchers = [
        {"id": 7, "type": "apartment", "budget_min": 750000, "budget_max": 850000, "notes": "أبي حوش"},
        {"id": 8, "type": "apartment", "budget_min": 1100000, "budget_max": 1300000},
    ]
    model.answers["match_searchers"] = {
        "results": [
            {"id": 7, "score": 0.8, "reason": "ميزانيته تناسب النموذج", "unit_id": 999},
            {"id": 70, "score": 0.5},
        ]
    }

    output = call(
        client, "match_searchers", {"project": {"type": "apartment"}, "units": units, "searchers": searchers}
    )["output"]

    assert output["results"] == [{"id": 7, "score": 0.8, "reason": "ميزانيته تناسب النموذج", "unit_id": None}]

    model.answers["match_searchers"] = httpx.ConnectError("down")
    degraded = call(client, "match_searchers", {"project": {}, "units": units, "searchers": searchers})
    assert degraded["degraded"] is True
    assert {(r["id"], r["unit_id"]) for r in degraded["output"]["results"]} == {(7, 40), (8, 41)}


def test_brokers_search_requests_in_their_own_collection(client: TestClient, model: ScriptedModel) -> None:
    request = {"city": "الرياض", "neighborhoods": ["النرجس"], "property_types": ["villa"], "desc": "فيلا بمسبح"}
    index(client, "request", 77, request)
    model.answers["search_requests"] = {"results": [{"id": 77, "score": 0.7, "reason": "يطلب فيلا بمسبح"}]}
    candidates = [{"id": 77, "types": ["villa"], "neighborhoods": ["النرجس"], "desc": "فيلا بمسبح"}]

    output = call(client, "search_requests", {"need": {**NEED, "free_text": "مسبح"}, "candidates": candidates})[
        "output"
    ]

    assert output["results"][0]["id"] == 77
