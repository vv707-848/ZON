"""Ranking agents: candidates only, an id and a score each — nothing written — and the degraded answer."""

from typing import Any

import httpx
import pytest
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
    seen: set[str] = set()

    def broken_first(payload: dict[str, Any]) -> dict[str, Any]:  # each order's first attempt breaks the schema
        order = str([c["id"] for c in payload["candidates"]])
        if order in seen:
            return {"results": [{"id": 2, "score": 0.5}]}
        seen.add(order)
        return {"results": "not a list"}

    model.answers["search_listings"] = broken_first  # both orders, each tried once more

    body = call(client, "search_listings", {"need": NEED, "candidates": _candidates(1, 2)})

    assert [r["id"] for r in body["output"]["results"]] == [2]
    temperatures = sorted(t for *_, t in model.calls_of("search_listings"))
    assert temperatures == [0.0, 0.0, 0.1, 0.1], "a ranking at 0, in both orders"


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
    assert len(shown) == 8 and shown[0] == 99, "a shortlist, the seekers' nearest model first"
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


def test_what_breaks_a_stated_condition_is_no_result_whatever_the_model_said(
    client: TestClient, model: ScriptedModel
) -> None:
    """Owner's rule (21 Nov): the budget (a tenth over its top at most) and the rooms are conditions of a result,
    not of its place: what breaks them is never shown — with the model, without it, when it times out. Two that keep
    them are two results; the list is never filled with what breaks them."""
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

    assert [r["id"] for r in found] == [3, 5], "two keep the wish: two results"
    assert matched == found

    open_need = {**NEED, "budget_max": None, "free_text": "مدخل خاص"}  # no number stated: nothing to break
    kept = call(client, "search_listings", {"need": open_need, "candidates": candidates})["output"]["results"]
    assert [r["id"] for r in kept] == [1, 2, 3, 4, 5]
    model.answers["search_listings"] = httpx.ConnectError("tunnel down")
    plain = call(client, "search_listings", {"need": need, "candidates": candidates})
    assert plain["degraded"] is True and sorted(r["id"] for r in plain["output"]["results"]) == [3, 5], (
        "with no model too"
    )


def test_a_request_whose_own_budget_or_rooms_the_offer_breaks_is_not_shown(
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

    assert [r["id"] for r in found] == [3, 4], "equal scores: the closest budget first; what breaks it is not shown"


def test_the_model_reads_at_most_15_search_and_8_matching_candidates_each_cut_at_400(
    client: TestClient, model: ScriptedModel
) -> None:
    """The owner's bounds (issue #119): Laravel sends up to 300 candidates of 600 characters; the model reads a
    shortlist — 15 in search, 8 per matching list — with each description cut at 400. The fields stay whole."""
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

    (_, search, _), _ = model.calls_of("search_listings")  # and the same shortlist the other way round
    (_, brokers, _), _ = model.calls_of("search_requests")
    (_, matching, _), _ = model.calls_of("matching")
    assert [len(search["candidates"]), len(brokers["candidates"])] == [15, 15]
    assert [len(matching["matched"]), len(matching["broaden"])] == [8, 8]
    shown = [*search["candidates"], *brokers["candidates"], *matching["matched"], *matching["broaden"]]
    assert {len(c["desc"]) for c in shown} == {400} and all(long.startswith(c["desc"]) for c in shown)
    nearest = next(c for c in search["candidates"] if c["id"] == 40)  # the 15 nearest the top of the budget
    assert nearest["area"] == 180 and nearest["price"] == 800040, "fields stay"


def test_clean_listing_reads_the_whole_text(client: TestClient, model: ScriptedModel) -> None:
    text = "شقة دور أرضي بمدخلين وحوش واسع ومطبخ راكب " * 25  # 1,075 characters
    model.answers["clean_listing"] = {"desc_clean": "شقة دور أرضي بمدخلين", "features": []}

    call(client, "clean_listing", {"type": "apartment", "city": "الرياض", "text": text})

    ((_, sent, _),) = model.calls_of("clean_listing")
    assert sent["text"] == text, "only the ranking agents' candidates are cut"


def test_the_model_writes_its_best_five_and_the_list_goes_on_in_the_retrieval_order(
    client: TestClient, model: ScriptedModel
) -> None:
    """What the model writes is the time (#119: ten results of ~16 tokens took ~5 of a search's 6.9 s on the A40):
    it is asked for five, chosen from all fifteen it reads, and the list goes on to Laravel's ten in the retrieval's
    order, none scored above the result before it. It found fewer than five: no more fit, and none are added."""
    many = _candidates(*range(1, 13))
    best = [{"id": i, "score": s} for i, s in ((9, 0.95), (12, 0.9), (3, 0.9), (7, 0.8), (1, 0.6))]
    model.answers["search_listings"] = {"results": best}
    read_eight = [{"id": i, "score": s} for i, s in ((8, 0.95), (6, 0.9), (3, 0.9), (7, 0.8), (1, 0.6))]
    model.answers["matching"] = {"matched": read_eight, "broaden": [{"id": 101, "score": 0.7}]}

    found = call(client, "search_listings", {"need": NEED, "candidates": many})["output"]["results"]
    pairs = call(client, "matching", {"need": NEED, "matched": many, "broaden": _candidates(101, 102)})["output"]

    (_, sent, _), _ = model.calls_of("search_listings")
    assert sent["limit"] == 5 and len(sent["candidates"]) == 12, "asked for five, reading every candidate"
    # no free text: the shortlist's order is the price's distance from the top of the budget, then the id
    assert [r["id"] for r in found] == [9, 12, 3, 7, 1, 11, 10, 8, 6, 5], "then the shortlist's order, to ten"
    tail = [r["score"] for r in found[4:]]
    assert tail == sorted(tail, reverse=True) and max(tail[1:]) <= 0.6, "none above the result before it"
    assert model.calls_of("matching")[0][1]["limit"] == 5
    # the eight read are the eight nearest the top of the budget (12 … 5): 3 and 1 are not among them, so three of
    # the five it wrote are candidates — fewer than asked: what it found, nothing added
    assert [r["id"] for r in pairs["matched"]] == [8, 6, 7]
    assert [r["id"] for r in pairs["broaden"]] == [101], "fewer than asked: nothing added"


def test_the_developer_s_model_reads_15_searchers_and_8_unit_models_cut_at_350(
    client: TestClient, model: ScriptedModel
) -> None:
    """52,370 characters at production's sizes (100 unit models of 400, 60 searchers of 500) timed out eight at once
    on the A40 (#119): now the 15 closest searchers and the 8 unit models nearest them, each text cut at 350. It
    writes its best five; the rest follow by closeness, each with its nearest model."""
    units = [{"id": n, "type": "apartment", "price": 500000 + n * 10000, "desc": "نموذج " * 100} for n in range(1, 101)]
    searchers = [
        {
            "id": i,
            "type": "apartment",
            "budget_min": 900000 + i * 10000,
            "budget_max": 1000000 + i * 10000,
            "notes": "أبي حوش " * 100,
        }
        for i in range(1, 41)
    ]
    model.answers["match_searchers"] = {"results": [{"id": i, "score": 0.9, "unit_id": 50} for i in (5, 3, 4, 1, 2)]}

    output = call(client, "match_searchers", {"project": {}, "units": units, "searchers": searchers})["output"]

    ((_, sent, _),) = model.calls_of("match_searchers")
    assert (len(sent["searchers"]), len(sent["units"]), sent["limit"]) == (15, 8, 5)
    assert {len(s["notes"]) for s in sent["searchers"]} == {350} and {len(u["desc"]) for u in sent["units"]} == {350}
    assert len(json_size := __import__("json").dumps(sent, ensure_ascii=False)) < 12000, json_size
    ids = [r["id"] for r in output["results"]]
    assert ids[:5] == [1, 2, 3, 4, 5] and len(ids) == 10 and set(ids[5:]) <= {s["id"] for s in sent["searchers"]}
    assert all(r["score"] <= 0.9 and r["unit_id"] is not None for r in output["results"][5:])


def test_a_ranking_is_asked_at_temperature_0_and_an_exact_tie_goes_to_the_closest_budget_whatever_the_order(
    client: TestClient, model: ScriptedModel
) -> None:
    """Stability (#119: 0.78 · 0.76 · 0.47 on the A40): the same candidates in the opposite order must give the same
    first three. A sample drawn at 0.3 differs by itself, and equal scores kept the order the candidates came in."""
    tied = [{"id": i, "score": 0.9} for i in (2, 5, 3)] + [{"id": 4, "score": 0.7}]
    model.answers["search_listings"] = {"results": tied}
    model.answers["matching"] = {"matched": tied, "broaden": []}
    model.answers["match_searchers"] = {"results": [{"id": i, "score": 0.8, "unit_id": 40} for i in (7, 9, 8)]}
    units = [{"id": 40, "type": "apartment", "price": 800000}]
    searchers = [{"id": i, "type": "apartment", "budget_max": 850000} for i in (7, 8, 9)]

    found = call(client, "search_listings", {"need": NEED, "candidates": _candidates(2, 3, 4, 5)})["output"]
    pairs = call(client, "matching", {"need": NEED, "matched": _candidates(2, 3, 4, 5)})["output"]
    seekers = call(client, "match_searchers", {"project": {}, "units": units, "searchers": searchers})["output"]

    # equal scores: the price closest to the budget's top (950,000), then the lower id — whatever the order
    assert [r["id"] for r in found["results"]] == [5, 3, 2, 4]
    assert [r["id"] for r in pairs["matched"]] == [5, 3, 2, 4]
    assert [r["id"] for r in seekers["results"]] == [7, 8, 9], "an equal fit: the lower id"
    assert {agent: temperature for agent, _, temperature in model.calls} == {
        "search_listings": 0.0,
        "matching": 0.0,
        "match_searchers": 0.0,
    }


def test_broaden_shows_no_offer_that_breaks_the_wish_s_rooms_or_budget(
    client: TestClient, model: ScriptedModel
) -> None:
    """«توسيع الإدراك» is what the budget buys that serves the wish. The A40 put an offer a room short in its first
    three in 2 of 12 cases, lists of three (zero_top3_broaden 16.7%): it is no option at all, so it is not shown.
    Nor in «مطابق» (owner's rule, 21 Nov): there, the tenth over the budget still forgives."""
    need = {**NEED, "budget_max": 770_000, "bedrooms": 4}
    floor = {"type": "floor", "neighborhood": "اليرموك", "desc": "دور مستقل"}
    broaden = [
        {"id": 31, **floor, "price": 650_000, "bedrooms": 3},  # a room short
        {"id": 32, **floor, "price": 760_000, "bedrooms": 4},
        {"id": 33, **floor, "price": 900_000, "bedrooms": 5},  # 17% over the budget
    ]
    matched = [{"id": 1, "type": "apartment", "neighborhood": "النرجس", "price": 700_000, "bedrooms": 3, "desc": "شقة"}]
    model.answers["matching"] = {
        "matched": [{"id": 1, "score": 0.8}],
        "broaden": [{"id": i, "score": 0.9} for i in (31, 32, 33)],
    }

    output = call(client, "matching", {"need": need, "matched": matched, "broaden": broaden})["output"]
    assert [r["id"] for r in output["broaden"]] == [32]
    assert output["matched"] == [], "a room short of the wish: no result"

    model.answers["matching"] = httpx.ConnectError("down")
    degraded = call(client, "matching", {"need": need, "matched": matched, "broaden": broaden})["output"]
    assert [r["id"] for r in degraded["broaden"]] == [32], "with no model too"


@pytest.mark.parametrize("model_down", [False, True])
def test_the_one_offer_that_fits_is_never_cut_for_ten_that_break_the_wish(
    client: TestClient, model: ScriptedModel, model_down: bool
) -> None:
    """Review of 21 Nov: a four-room wish at a million, ten flats of two rooms and one of four at 990,000 — the list
    was cut to ten before what breaks the rooms went last, so the one that fits was dropped, with the model and
    without. What keeps the rooms and the budget is now first before any cut."""
    need = {**NEED, "budget_max": 1_000_000, "bedrooms": 4}
    two = [
        {"id": i, "type": "apartment", "neighborhood": "النرجس", "price": 1_000_000, "bedrooms": 2, "desc": "شقة"}
        for i in range(1, 11)
    ]
    four = {"id": 99, "type": "apartment", "neighborhood": "النرجس", "price": 990_000, "bedrooms": 4, "desc": "شقة"}
    bad = {"results": [{"id": i, "score": 0.9} for i in range(1, 6)]}
    model.answers["search_listings"] = httpx.ConnectError("down") if model_down else bad
    model.answers["matching"] = (
        httpx.ConnectError("down")
        if model_down
        else {"matched": bad["results"], "broaden": [{"id": i, "score": 0.9} for i in (51, 52, 53)]}
    )

    found = call(client, "search_listings", {"need": need, "candidates": [*two, four]})["output"]["results"]
    pairs = call(
        client,
        "matching",
        {
            "need": need,
            "matched": [*two, four],
            "broaden": [*({**f, "id": 50 + n} for n, f in enumerate(two[:3], 1)), {**four, "id": 98}],
        },
    )

    assert [r["id"] for r in found] == [99], "one keeps the wish: one result, never ten that break it"
    assert [r["id"] for r in pairs["output"]["matched"]] == [99]
    assert [r["id"] for r in pairs["output"]["broaden"]] == [98]


def test_a_model_that_ranks_positions_gives_the_same_list_whichever_order_came(
    client: TestClient, model: ScriptedModel
) -> None:
    """A40, 21 Nov: at temperature 0 the first three survived a reversed shortlist in 0.70 · 0.71 · 0.61 of the cases.
    Asked in both orders at once, each candidate at its mean score, a model that ranks positions cannot carry the
    order into the answer. The other order keeps what breaks the wish last (1,200,000 is over a 950,000 budget)."""

    def first_come(payload: dict[str, object]) -> dict[str, object]:
        shown = payload["candidates"]
        assert isinstance(shown, list)
        return {"results": [{"id": c["id"], "score": round(1 - n / 10, 3)} for n, c in enumerate(shown[:5])]}

    model.answers["search_listings"] = first_come
    over = {**_candidates(9)[0], "price": 1_200_000}
    given = [*_candidates(1, 2, 3, 4, 5, 6, 7), over]
    ahead = call(client, "search_listings", {"need": NEED, "candidates": given})["output"]["results"]
    back = call(client, "search_listings", {"need": NEED, "candidates": [*given[-2::-1], over]})["output"]["results"]

    assert [r["id"] for r in ahead[:3]] == [r["id"] for r in back[:3]]
    # the two calls of the first search, as they reached the model (in either order: they are asked at once)
    first, other = sorted((c[1] for c in model.calls_of("search_listings")[:2]), key=lambda p: p["candidates"][0]["id"])
    # the order the model reads is the candidates' own (no free text: nearest the top of the budget first), whatever
    # order they came in; 9 (over the budget) is not read at all
    assert [c["id"] for c in other["candidates"]] == [7, 6, 5, 4, 3, 2, 1]
    assert [c["id"] for c in first["candidates"]] == [1, 2, 3, 4, 5, 6, 7], "and the other order of the two"
    third, fourth = (c[1]["candidates"] for c in model.calls_of("search_listings")[2:4])
    assert {str([c["id"] for c in third]), str([c["id"] for c in fourth])} == {
        str(list(range(7, 0, -1))),
        str(list(range(1, 8))),
    }


def test_one_order_failing_leaves_the_other_to_answer_alone(client: TestClient, model: ScriptedModel) -> None:
    model.answers["search_listings"] = [httpx.ConnectError("down"), {"results": [{"id": 2, "score": 0.9}]}]

    body = call(client, "search_listings", {"need": NEED, "candidates": _candidates(1, 2)})

    assert body["degraded"] is False and [r["id"] for r in body["output"]["results"]] == [2]


def test_the_compact_mode_asks_once_with_short_descriptions_and_the_whole_need(
    client: TestClient, model: ScriptedModel, monkeypatch: pytest.MonkeyPatch
) -> None:
    """ZON_RANK_MODE=compact (A40, 21 Nov: both orders doubled the calls, search p95 10.2 s): one call per search
    and per matching, each description cut at 160 — and the searcher's conditions sent whole, free text included."""
    from zon_agents.pipelines import ranking

    monkeypatch.setattr(ranking, "RANK_MODE", "compact")
    long = "شقة واسعة قريبة من الخدمات " * 30
    need = {**NEED, "bedrooms": 3, "purpose": "sale", "free_text": "أبي " + "مدخل خاص وحوش " * 40}
    many = [{**c, "desc": long, "bedrooms": 3} for c in _candidates(*range(1, 9))]
    model.answers["search_listings"] = {"results": [{"id": 2, "score": 0.9}]}
    model.answers["matching"] = {"matched": [{"id": 2, "score": 0.9}], "broaden": []}

    call(client, "search_listings", {"need": need, "candidates": many})
    call(client, "matching", {"need": need, "matched": many, "broaden": []})

    ((_, search, _),) = model.calls_of("search_listings")
    ((_, matching, _),) = model.calls_of("matching")
    assert {len(c["desc"]) for c in [*search["candidates"], *matching["matched"]]} == {160}
    assert search["need"] == need and matching["need"] == need, "the conditions whole"
