"""Ranking agents: candidates only, an id and a score each — nothing written — and the degraded answer."""

from typing import Any

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
            {"id": 3, "score": 0.9},
            {"id": 99, "score": 0.8},  # not a candidate
            {"id": 1, "score": 0.7, "reason": "دور أرضي — اتصل 0551234567"},  # a key nobody asked for
            {"id": 3, "score": 0.6},  # twice
        ]
    }

    body = call(
        client, "search_listings", {"need": {**NEED, "free_text": "دور أرضي"}, "candidates": _candidates(1, 2, 3)}
    )

    assert body["output"] == {"results": [{"id": 3, "score": 0.9}, {"id": 1, "score": 0.7}]}
    assert body["degraded"] is False


def _properties(schema: Any) -> set[str]:
    """Every property name anywhere in a JSON schema."""
    if isinstance(schema, dict):
        found = set(schema.get("properties", {})) if isinstance(schema.get("properties"), dict) else set()
        return found.union(*(_properties(value) for value in schema.values()))
    if isinstance(schema, list):
        return set().union(*(_properties(value) for value in schema))
    return set()


def test_the_model_is_held_to_ids_and_scores(client: TestClient, model: ScriptedModel) -> None:
    """What generation is constrained to (NativeOutput → SGLang): no field the model could write a sentence in."""
    _indexed(client)
    need = {**NEED, "free_text": "دور أرضي"}
    model.answers["search_listings"] = {"results": [{"id": 1, "score": 0.5}]}
    model.answers["search_requests"] = {"results": []}
    model.answers["matching"] = {"matched": [], "broaden": []}
    model.answers["match_searchers"] = {"results": []}

    call(client, "search_listings", {"need": need, "candidates": _candidates(1)})
    call(client, "search_requests", {"need": need, "candidates": [{"id": 5, "desc": "شقة"}]})
    call(client, "matching", {"need": need, "matched": _candidates(1)})
    call(client, "match_searchers", {"project": {}, "units": [{"id": 40}], "searchers": [{"id": 7}]})

    assert {agent: _properties(schema) for agent, schema in model.schemas.items()} == {
        "search_listings": {"results", "id", "score"},
        "search_requests": {"results", "id", "score"},
        "matching": {"matched", "broaden", "id", "score"},
        "match_searchers": {"results", "id", "score", "unit_id"},
    }


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
    assert all(set(r) == {"id", "score"} for r in body["output"]["results"])


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


def test_matching_keeps_two_separate_lists_and_ranks_units_by_their_descriptions(
    client: TestClient, model: ScriptedModel
) -> None:
    _indexed(client)
    unit = {"project_id": 5, "city": "الرياض", "neighborhood": "النرجس", "property_type": "apartment", "model": "C1"}
    index(client, "project_unit", 40, {**unit, "desc_clean": "نموذج بحوش ومدخل خاص"})
    model.answers["matching"] = {
        "matched": [
            {"id": 1, "score": 0.9},
            {"id": 2, "score": 0.4},  # from the other list
        ],
        "broaden": [{"id": 2, "score": 0.6}],
    }
    payload = {
        "need": {**NEED, "free_text": "حوش ومدخل"},
        "matched": _candidates(1),
        "broaden": _candidates(2),
        "units": [{"id": 40, "model": "C1", "project_id": 5}],
    }

    output = call(client, "matching", payload)["output"]

    assert output["matched"] == [{"id": 1, "score": 0.9}]
    assert output["broaden"] == [{"id": 2, "score": 0.6}]
    assert output["units"][0]["id"] == 40 and set(output["units"][0]) == {"id", "score"}


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
            {"id": 7, "score": 0.8, "unit_id": 999},  # not one of his models
            {"id": 70, "score": 0.5},
        ]
    }

    output = call(
        client, "match_searchers", {"project": {"type": "apartment"}, "units": units, "searchers": searchers}
    )["output"]

    assert output["results"] == [{"id": 7, "score": 0.8, "unit_id": None}]

    model.answers["match_searchers"] = httpx.ConnectError("down")
    degraded = call(client, "match_searchers", {"project": {}, "units": units, "searchers": searchers})
    assert degraded["degraded"] is True
    assert {(r["id"], r["unit_id"]) for r in degraded["output"]["results"]} == {(7, 40), (8, 41)}


def test_a_project_with_many_unit_models_shows_the_model_a_shortlist_its_seekers_nearest_first(
    client: TestClient, model: ScriptedModel
) -> None:
    units = [{"id": n, "type": "apartment", "price": 500000 + n * 10000, "desc": "نموذج " * 60} for n in range(1, 101)]
    searchers = [{"id": 7, "type": "apartment", "budget_min": 1480000, "budget_max": 1500000}]  # nearest: unit 99
    model.answers["match_searchers"] = {"results": [{"id": 7, "score": 0.9, "unit_id": 99}]}

    output = call(client, "match_searchers", {"project": {}, "units": units, "searchers": searchers})["output"]

    shown = [u["id"] for u in model.calls_of("match_searchers")[0][1]["units"]]
    assert len(shown) == 30 and shown[0] == 99, "a shortlist, the seekers' nearest model first"
    assert output["results"] == [{"id": 7, "score": 0.9, "unit_id": 99}]


def test_brokers_search_requests_in_their_own_collection(client: TestClient, model: ScriptedModel) -> None:
    request = {"city": "الرياض", "neighborhoods": ["النرجس"], "property_types": ["villa"], "desc": "فيلا بمسبح"}
    index(client, "request", 77, request)
    model.answers["search_requests"] = {"results": [{"id": 77, "score": 0.7}]}
    candidates = [{"id": 77, "types": ["villa"], "neighborhoods": ["النرجس"], "desc": "فيلا بمسبح"}]

    output = call(client, "search_requests", {"need": {**NEED, "free_text": "مسبح"}, "candidates": candidates})[
        "output"
    ]

    assert output["results"][0]["id"] == 77


def test_what_breaks_a_stated_condition_comes_after_what_does_not_whatever_the_model_said(
    client: TestClient, model: ScriptedModel
) -> None:
    need = {**NEED, "budget_max": 900_000, "bedrooms": 3, "free_text": "مدخل خاص"}
    flat = {"type": "apartment", "neighborhood": "النرجس", "desc": "شقة بمدخل خاص"}
    candidates = [
        {"id": 1, **flat, "price": 1_000_000, "bedrooms": 3},  # 11% over the top
        {"id": 2, **flat, "price": 880_000, "bedrooms": 2},  # a room short
        {"id": 3, **flat, "price": 990_000, "bedrooms": 4},  # 10% over: still within the tenth
        {"id": 4, **flat, "price": 700_000, "bedrooms": None},  # rooms asked for and not stated
        {"id": 5, **flat, "price": 850_000, "bedrooms": 3},
    ]
    answer = [{"id": i, "score": round(1 - i / 10, 1)} for i in (1, 2, 3, 4, 5)]
    model.answers["search_listings"] = {"results": answer}
    model.answers["matching"] = {"matched": answer, "broaden": []}

    found = call(client, "search_listings", {"need": need, "candidates": candidates})["output"]["results"]
    matched = call(client, "matching", {"need": need, "matched": candidates})["output"]["matched"]

    assert [r["id"] for r in found] == [3, 5, 1, 2, 4], "none removed; each group in the model's order"
    assert [r["score"] for r in found] == [0.7, 0.5, 0.5, 0.5, 0.5], "moved down: not a score above the ones before"
    assert matched == found

    open_need = {**NEED, "budget_max": None, "free_text": "مدخل خاص"}  # no number stated: nothing to break
    kept = call(client, "search_listings", {"need": open_need, "candidates": candidates})["output"]["results"]
    assert [r["id"] for r in kept] == [1, 2, 3, 4, 5]
    model.answers["search_listings"] = httpx.ConnectError("tunnel down")
    plain = call(client, "search_listings", {"need": need, "candidates": candidates})
    assert plain["degraded"] is True and [r["id"] for r in plain["output"]["results"]][:2] == [3, 5], (
        "with no model too"
    )


def test_a_request_whose_own_budget_or_rooms_the_offer_breaks_comes_last(
    client: TestClient, model: ScriptedModel
) -> None:
    offer = {**NEED, "budget_max": 600_000, "bedrooms": 4, "free_text": "شقة بمطبخ راكب وواجهة شمالية"}
    wish = {"types": ["apartment"], "neighborhoods": ["النرجس"], "desc": "أبغى شقة مطبخ راكب"}
    requests = [
        {"id": 1, **wish, "budget_max": 480_000},  # the offer is a quarter over this budget
        {"id": 2, **wish, "budget_max": 650_000, "rooms_min": 5},  # wants a room more than the offer has
        {"id": 3, **wish, "budget_max": 560_000, "rooms_min": 4},  # 7% over: within the tenth
        {"id": 4, **wish},  # no budget stated
    ]
    model.answers["search_requests"] = {"results": [{"id": i, "score": 0.9} for i in (1, 2, 3, 4)]}

    found = call(client, "search_requests", {"need": offer, "candidates": requests})["output"]["results"]

    assert [r["id"] for r in found] == [3, 4, 1, 2]


def test_the_model_reads_at_most_15_search_and_17_matching_candidates_each_cut_at_400(
    client: TestClient, model: ScriptedModel
) -> None:
    """The owner's bounds (issue #119): Laravel sends up to 300 candidates of 600 characters; the model reads a
    shortlist — 15 in search, 17 per matching list — with each description cut at 400. The fields stay whole."""
    long = "شقة واسعة قريبة من الخدمات " * 30  # 810 characters
    many = [{**c, "desc": long, "area": 180} for c in _candidates(*range(1, 41))]
    model.answers["search_listings"] = {"results": [{"id": 1, "score": 0.9}]}
    model.answers["search_requests"] = {"results": [{"id": 1, "score": 0.9}]}
    model.answers["matching"] = {"matched": [{"id": 1, "score": 0.9}], "broaden": [{"id": 101, "score": 0.8}]}

    call(client, "search_listings", {"need": NEED, "candidates": many})
    requests = [{"id": i, "types": ["apartment"], "budget_max": 900000, "desc": long} for i in range(1, 41)]
    call(client, "search_requests", {"need": NEED, "candidates": requests})
    broaden = [{**c, "id": c["id"] + 100} for c in many]
    call(client, "matching", {"need": NEED, "matched": many, "broaden": broaden})

    ((_, search, _),) = model.calls_of("search_listings")
    ((_, brokers, _),) = model.calls_of("search_requests")
    ((_, matching, _),) = model.calls_of("matching")
    assert [len(search["candidates"]), len(brokers["candidates"])] == [15, 15]
    assert [len(matching["matched"]), len(matching["broaden"])] == [17, 17]
    shown = [*search["candidates"], *brokers["candidates"], *matching["matched"], *matching["broaden"]]
    assert {len(c["desc"]) for c in shown} == {400} and all(long.startswith(c["desc"]) for c in shown)
    assert search["candidates"][0]["area"] == 180 and search["candidates"][0]["price"] == 800001, "fields stay"


def test_clean_listing_reads_the_whole_text(client: TestClient, model: ScriptedModel) -> None:
    text = "شقة دور أرضي بمدخلين وحوش واسع ومطبخ راكب " * 25  # 1,075 characters
    model.answers["clean_listing"] = {"desc_clean": "شقة دور أرضي بمدخلين", "features": []}

    call(client, "clean_listing", {"type": "apartment", "city": "الرياض", "text": text})

    ((_, sent, _),) = model.calls_of("clean_listing")
    assert sent["text"] == text, "only the ranking agents' candidates are cut"
