"""lab market: the owner's market texts, measured with no expected answer (zon_agents/lab/market.py)."""

import asyncio
import re

from pydantic_ai.models.function import FunctionModel

from tests.conftest import ScriptedModel
from zon_agents.lab import market
from zon_agents.llm import LLM
from zon_agents.profiles import load_profile


def test_the_stream_is_split_where_an_offer_or_a_wish_starts_masked_as_laravel_masks() -> None:
    stream = (
        "في الطايف فيلا دورين المطلوب مليون وخمسمية الف للتواصل ٠٥٥٨٠٨٣٦٦٠ وكل الخدمات قريبة من البيت والمدارس "
        "والمساجد والشارع التجاري ممتاز للسكن العائلي "
        "للبيع شقة في حي الروضة بجدة ثلاث غرف وصالة ومطبخ راكب، السعر 880,000 ريال شوف https:/share.google/x1 "
        "والموقع ممتاز قريب من الخدمات والمدارس والمساجد وكل شي "
        "مطلوب فيلا في الطايف حي الوسام ميزانيتي مليونين تقبل البنك ويكون فيها مصعد ومدخلين ومجلس رجال ومجلس نساء "
        "والعمر ما يزيد عن عشر سنوات وقريبة من المدارس "
        "للبيع شقة في حي الروضة بجدة ثلاث غرف وصالة ومطبخ راكب، السعر 880,000 ريال شوف https:/share.google/x1 "
        "والموقع ممتاز قريب من الخدمات والمدارس والمساجد وكل شي"
    )
    texts = market.build(stream)

    assert [(t["kind"], t["type"]) for t in texts] == [("offer", "villa"), ("offer", "apartment"), ("wish", "villa")]
    assert "المطلوب مليون" in texts[0]["text"], "«المطلوب» (the asking price) starts no wish"
    joined = " ".join(t["text"] for t in texts)
    assert "[رقم]" in joined and "[رابط]" in joined and not re.search(r"\d{9}|share\.google", joined)


def test_the_file_holds_no_phone_number_or_link_the_repository_would_publish() -> None:
    texts = market.load()
    assert len(texts) >= 150 and {t["kind"] for t in texts} == {"offer", "wish"}
    for t in texts:
        assert not re.search(r"(?<!\d)0?5\d{8}(?!\d)|https?:/|www\.|@\w+\.", t["text"]), t["id"]


def test_what_any_right_answer_satisfies_is_read_from_the_text_alone() -> None:
    offer = {"kind": "offer", "text": "للبيع فيلا فيها مجلس رجال ومصعد، السعر 880000"}
    wish = {"kind": "wish", "text": "مطلوب شقة في جدة بحدود 600 الف"}

    assert market.checks(
        offer, {"desc_clean": "فيلا بمجلس رجال ومصعد، السعر 880000", "features": ["مصعد", "مجلس رجال"]}
    ) == {"grounded": 1.0, "numbers": 1.0, "arabic": 1.0}
    assert market.checks(offer, {"desc_clean": "Villa 900000", "features": ["مسبح"]}) == {
        "grounded": 0.0,
        "numbers": 0.0,
        "arabic": 0.0,
    }
    right = "هلا والله، أبشر بسعدك! عندك «البحث الدلالي» تختار فيه المدينة والحي."
    assert market.checks(wish, {"answer": right}) == {"routes": 1.0, "numbers": 1.0, "asks": 1.0, "arabic": 1.0}
    assert market.checks(wish, {"answer": "كم ميزانيتك؟ الأسعار من 700 ألف"})["routes"] == 0.0


def test_every_text_is_asked_and_what_missed_is_kept_with_what_it_wrote(model: ScriptedModel) -> None:
    model.answers["clean_listing"] = {"desc_clean": "فيلا للبيع", "features": ["مسبح"]}
    model.answers["chat"] = {"answer": "عندك «البحث الدلالي».", "covered": True, "citations": []}
    llm = LLM(
        FunctionModel(model),
        model.prompts,
        profile=load_profile("qwen3.8-27b-fp8"),
        max_concurrency=4,
        budget_seconds=5,
    )
    items = [
        {"id": "a", "kind": "offer", "type": "villa", "text": "للبيع فيلا"},
        {"id": "b", "kind": "wish", "type": "villa", "text": "مطلوب فيلا"},
    ]
    found = asyncio.run(market.market(llm, items))

    assert found["clean_listing"]["checks"] == {"arabic": 1.0, "grounded": 0.0, "numbers": 1.0}
    assert [m["id"] for m in found["clean_listing"]["missed"]] == ["a"]
    assert found["chat"]["checks"]["routes"] == 1.0 and found["chat"]["missed"] == []


def test_an_offer_longer_than_the_app_accepts_is_not_asked_and_amounts_are_read_as_written(
    model: ScriptedModel,
) -> None:
    """The app refuses a text over 300 letters until he shortens it (Listing::DESC_MAX): the lab does not shorten it
    for him. And an amount said in digits and words is one amount, however the answer writes it."""
    model.answers["clean_listing"] = {"desc_clean": "فيلا للبيع", "features": []}
    llm = LLM(
        FunctionModel(model),
        model.prompts,
        profile=load_profile("qwen3.8-27b-fp8"),
        max_concurrency=4,
        budget_seconds=5,
    )
    items = [
        {"id": "short", "kind": "offer", "type": "villa", "text": "للبيع فيلا " + "ب" * 289},
        {"id": "long", "kind": "offer", "type": "villa", "text": "للبيع فيلا " + "ب" * 290},
    ]
    found = asyncio.run(market.market(llm, items))
    assert found["clean_listing"]["asked"] == 1 and found["clean_listing"]["over_limit"] == 1

    offer = {"kind": "offer", "text": "للبيع فيلا المطلوب 2 مليون و 200 الف والمساحه 39 الف و 412"}
    assert market.checks(offer, {"desc_clean": "فيلا بسعر 2.2 مليون ومساحة 39412", "features": []})["numbers"] == 1.0
    assert market.checks(offer, {"desc_clean": "فيلا بسعر 2200200", "features": []})["numbers"] == 0.0
