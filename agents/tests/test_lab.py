"""The model lab: valid cases, scorers that reward the right answer and nothing else, and a run end to end."""

import asyncio
import json
from datetime import UTC, datetime
from typing import Any

from pydantic_ai.models.function import FunctionModel

from tests.conftest import ScriptedModel
from zon_agents.lab.cases import EXPECTS, Case, check, load_cases
from zon_agents.lab.runner import RANKING, report, run_cases
from zon_agents.lab.scoring import ndcg, number_guard, score
from zon_agents.llm import LLM
from zon_agents.profiles import load_profile


def _first(agent: str, tag: str | None = None) -> Case:
    return next(c for c in load_cases() if c.agent == agent and (tag is None or tag in c.tags))


def test_the_committed_cases_are_valid_and_cover_every_agent() -> None:
    cases = load_cases()

    assert check(cases) == []
    assert {c.agent for c in cases} == set(EXPECTS)
    assert sum("real" in c.tags for c in cases) >= 30, "the real market's ads and requests are in"


def test_the_best_order_scores_one_and_a_wrong_one_is_caught() -> None:
    case = _first("search_listings", "injection")
    grades = case.expected(EXPECTS["search_listings"]).model_dump()["grades"]
    best = sorted(grades, key=lambda i: -grades[i])
    inject = case.expect["inject"]

    perfect = score(case, {"results": [{"id": i, "score": 1.0} for i in best]})
    worst_first = [inject, *[i for i in reversed(best) if i != inject]]
    fooled = score(case, {"results": [{"id": i, "score": 1.0} for i in worst_first]})

    assert perfect.value == 1.0 and perfect.metrics["top1"] == 1.0 and perfect.metrics["zero_top3"] == 0.0
    assert fooled.value < 0.6 and fooled.metrics["inject_top3"] == 1.0 and fooled.metrics["zero_top3"] == 1.0
    assert ndcg([], grades, 10) == 0.0


def test_the_number_guard_is_laravels() -> None:
    rows = [{"neighborhood": "النرجس", "requests": 38, "avg_budget_max": 1150000, "rate": 0.71}]

    assert number_guard("النرجس الأعلى بـ38 طلباً ومتوسط ميزانية 1150 ألف، ونسبة مطابق 71%", rows, {})
    assert number_guard("ثلاثة أحياء، الثاني منها 3", rows, {}), "small counts and ordinals are free"
    assert not number_guard("زادت الطلبات 45%", rows, {}), "a percentage the rows do not hold"
    assert not number_guard("متوسط الميزانية 1.15 مليون", rows, {}), "PHP rounds 1.15 to 1.2: NumberGuard refuses it"
    assert number_guard("آخر 30 يوماً", [], {"days": 30}), "a number from the question's parameters"


def test_text_scorers_reward_the_facts_and_punish_what_was_never_said() -> None:
    case = _first("clean_listing")  # ground floor · private entrance · kitchen; deed and bank kept; no pool
    right = {
        "desc_clean": "شقة دور أرضي بمدخل خاص ومطبخ راكب، 3 غرف، الصك جاهز ويقبل البنك، 890 ألف",
        "features": ["دور أرضي", "مدخل خاص", "مطبخ راكب"],
    }
    wrong = {"desc_clean": "شقة بمسبح خاص، 5 غرف، 990 ألف، فرصة لا تعوض", "features": ["مسبح"]}

    good, bad = score(case, right), score(case, wrong)

    assert good.value == 1.0, good.metrics
    assert bad.metrics == {"features": 0.0, "keep": 0.0, "invented": 0.0, "numbers": 0.0}


def _case(case_id: str) -> Case:
    return next(c for c in load_cases() if c.id == case_id)


def test_the_superagent_scorers_know_the_report_and_its_parameters() -> None:
    gap = _case("select-023")  # two reports answer it; city and days matter
    all_cities = _case("select-022")  # «في كل المدن»: no city

    def one(report: str, params: dict[str, Any]) -> dict[str, Any]:
        return {"reports": [{"report": report, "params": params}]}

    assert score(gap, one("demand_supply_gap", {"city": "جدة", "days": 90})).value == 1.0
    assert score(gap, one("supply_vs_demand", {"city": "جدة", "days": 30})).value == 0.5
    assert score(gap, one("listing_funnel", {})).value == 0.0
    assert score(all_cities, one("top_property_types", {"days": 60, "city": "الرياض"})).value == 0.5


def test_several_questions_score_a_report_for_each_and_an_unasked_one_costs() -> None:
    two = _case("select-031")  # new users this week · revenue this month
    users, revenue = (
        {"report": "user_growth", "params": {"days": 7}},
        {"report": "revenue_summary", "params": {"days": 30}},
    )
    extra = {"report": "listing_funnel", "params": {"days": 30}}

    assert score(two, {"reports": [users, revenue]}).value == 1.0
    assert score(two, {"reports": [users]}).value == 0.5
    assert score(two, {"reports": [users, revenue, extra]}).value == 2 / 3
    assert score(_case("select-035"), {"reports": []}).value == 1.0, "no report answers the population of a city"
    assert score(_case("select-035"), {"reports": [users]}).value == 0.0


def test_an_exploration_step_deepens_what_was_found_and_stopping_is_right_only_when_it_is() -> None:
    deepen = _case("explore-003")  # النرجس leads Riyadh's demand
    clear = _case("explore-007")  # the last step after a clear picture

    assert score(deepen, {"report": "price_trend", "params": {"city": "الرياض", "neighborhood": "النرجس"}}).value == 1.0
    assert score(deepen, {"report": "price_trend", "params": {"city": "جدة"}}).value == 0.5
    assert score(deepen, {"report": "revenue_summary", "params": {}}).value == 0.0
    assert score(deepen, {"report": None, "params": {}}).value == 0.0, "one step in is too soon to stop"
    assert score(clear, {"report": None, "params": {}}).value == 1.0


def test_the_writer_is_scored_on_every_parts_rows_and_on_the_screen_it_proposes() -> None:
    explore = _case("write-012")  # demand · the gap · the price trend in النرجس
    text = "النرجس الأعلى طلباً بـ38 طلباً، وفجوة الفلل فيه 23، ومتوسط سعرها ارتفع إلى 2450000."
    announce = [{"text": "دعوة وسطاء الرياض إلى فلل النرجس", "action": "announce", "params": {"audience": "broker"}}]
    no_button = [{"text": "دعوة وسطاء الرياض إلى فلل النرجس", "action": None, "params": {}}]

    assert score(explore, {"analysis": text, "recommendations": announce}).value == 1.0
    assert score(explore, {"analysis": text, "recommendations": no_button}).metrics["action"] == 0.0
    assert score(explore, {"analysis": "ارتفع السعر 17%.", "recommendations": announce}).value == 0.0, (
        "17% is not in the rows"
    )


def _answers(model: ScriptedModel) -> None:
    """A plausible model for every agent: candidates as they came, short texts, the first report, no further step."""

    def ranked(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
        return [{"id": c["id"], "score": round(1 - n / 40, 3)} for n, c in enumerate(items)]

    model.answers |= {
        "search_listings": lambda p: {"results": ranked(p["candidates"])},
        "search_requests": lambda p: {"results": ranked(p["candidates"])},
        "matching": lambda p: {"matched": ranked(p["matched"]), "broaden": ranked(p["broaden"])},
        "match_searchers": lambda p: {
            "results": [{**r, "unit_id": p["units"][0]["id"]} for r in ranked(p["searchers"])]
        },
        "clean_listing": lambda p: {"desc_clean": p["text"][:200], "features": []},
        "summarize": {"summary": "ملخص", "signals": [], "recommended_action": "متابعة", "confidence": "medium"},
        "chat": {"answer": "جواب من المكتبة", "covered": True, "citations": [{"passage": 1, "quote_ar": ""}]},
        "report_select": lambda p: {"reports": [{"report": p["catalog"][0]["report"], "params": {}}]},
        "report_explore": {"report": None, "params": {}},
        "report_write": {"analysis": "تحليل الصفوف", "recommendations": []},
    }


def test_a_run_measures_every_agent_end_to_end_and_writes_the_report(model: ScriptedModel) -> None:
    _answers(model)
    cases = [c for agent in sorted(EXPECTS) for c in [c for c in load_cases() if c.agent == agent][:2]]
    llm = LLM(
        FunctionModel(model),
        model.prompts,
        profile=load_profile("qwen3.6-35b-a3b-fp8"),
        max_concurrency=8,
        budget_seconds=5,
    )

    outcomes = asyncio.run(run_cases(llm, cases, concurrency=8))
    written = report(outcomes, cases, llm, started=datetime.now(UTC), seconds=2.0, gpu="A40", notes=None)
    result = json.loads(json.dumps(written))

    assert set(result["agents"]) == set(EXPECTS)
    for entry in result["agents"].values():
        assert entry["failed"] == 0 and 0 <= entry["score"] <= 1, entry
    for agent in RANKING:
        assert "stability" in result["agents"][agent]["variants"]["reversed"], agent
    assert "digits" in result["agents"]["clean_listing"]["variants"]
    assert result["profile"]["name"] == "qwen3.6-35b-a3b-fp8" and result["gpu"] == "A40"
    assert result["dataset"]["cases"] == len(cases) and len(result["dataset"]["digest"]) == 16
    assert len(result["outcomes"]) == len(outcomes) > len(cases), "variants ran too"
    sent = [s for s in model.settings if s.get("extra_body", {}).get("chat_template_kwargs")]
    assert sent, "the profile's own request extras reached the model"


def test_a_model_that_fails_is_recorded_and_the_run_goes_on(model: ScriptedModel) -> None:
    _answers(model)
    model.answers["summarize"] = TimeoutError("too slow")
    cases = [c for agent in ("summarize", "report_select") for c in [c for c in load_cases() if c.agent == agent][:3]]
    llm = LLM(
        FunctionModel(model),
        model.prompts,
        profile=load_profile("qwen3.6-35b-a3b-escha-w2"),
        max_concurrency=4,
        budget_seconds=5,
    )

    outcomes = asyncio.run(run_cases(llm, cases, with_variants=False))

    failed = [o for o in outcomes if o.agent == "summarize"]
    assert len(failed) == 3 and all(o.error == "model: llm (timeout) unavailable" and o.score == 0.0 for o in failed)
    assert all(o.error is None for o in outcomes if o.agent == "report_select")
