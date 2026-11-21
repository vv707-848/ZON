"""Summaries and intake cleaning: no contact details out, units never mixed, failures left to Laravel."""

import json
from pathlib import Path

import httpx
import pytest
from fastapi.testclient import TestClient

from tests.conftest import ScriptedModel, call


def test_a_cleaned_description_never_carries_contact_details(client: TestClient, model: ScriptedModel) -> None:
    model.answers["clean_listing"] = {
        "desc_clean": "شقة دور أرضي بمدخلين. للتواصل 0551234567 أو www.example.com",
        "features": ["دور أرضي", "مدخلان"],
    }

    output = call(client, "clean_listing", {"type": "apartment", "text": "شقة دور ارضي مدخلين اتصل 0551234567"})[
        "output"
    ]

    assert output == {"desc_clean": "شقة دور أرضي بمدخلين. للتواصل أو", "features": ["دور أرضي", "مدخلان"]}


def test_a_placeholder_of_the_masking_never_comes_back_in_a_written_answer(
    client: TestClient, model: ScriptedModel
) -> None:
    """Laravel sends «للتواصل [رقم]» where a number was: a model that copies it back would hand the advertiser
    «[رقم]» as his description (found by the chain against the simulation, which copies the text as it is)."""
    model.answers["clean_listing"] = {"desc_clean": "شقة بمدخل خاص. للتواصل [رقم] أو [بريد] [رابط]", "features": []}

    output = call(client, "clean_listing", {"type": "apartment", "text": "شقة بمدخل خاص للتواصل [رقم]"})["output"]

    assert output["desc_clean"] == "شقة بمدخل خاص. للتواصل أو"


def test_unit_models_are_cleaned_in_one_call_and_only_those_asked(client: TestClient, model: ScriptedModel) -> None:
    model.answers["clean_units"] = {
        "units": [{"id": 2, "desc_clean": "مدخل خاص وحوش"}, {"id": 9, "desc_clean": "مختلق"}]
    }
    units = [{"id": 1, "model": "C1", "text": "تراس"}, {"id": 2, "model": "B2", "text": "مدخل خاص وحوش"}]

    output = call(client, "clean_units", {"project_type": "apartment", "units": units})["output"]

    assert output == {"units": [{"id": 2, "desc_clean": "مدخل خاص وحوش"}]}
    assert len(model.calls_of("clean_units")) == 1


def test_a_summary_keeps_its_shape(client: TestClient, model: ScriptedModel) -> None:
    model.answers["summarize"] = {
        "summary": "عميل جاد بميزانية نقدية.",
        "signals": ["كاش", "يرفض البعد"],
        "recommended_action": "عرض وحدات الشمال",
        "confidence": "high",
    }
    notes = [{"category": "cash", "date": "2026-08-01", "text": "العميل يدفع كاش"}]

    output = call(client, "summarize", {"scope": "client", "notes": notes})["output"]

    assert output["confidence"] == "high"
    assert output["signals"] == ["كاش", "يرفض البعد"]


def test_cleaning_waits_for_the_model_instead_of_inventing_a_text(client: TestClient, model: ScriptedModel) -> None:
    model.answers["clean_project"] = httpx.ConnectError("down")

    response = client.post("/agents/clean_project", json={"type": "apartment", "text": "مشروع قريب من الخدمات"})

    assert response.status_code == 503


def test_a_wording_that_changes_an_amount_is_refused_and_the_same_amount_in_another_form_kept(
    client: TestClient, model: ScriptedModel
) -> None:
    """A40, 21 Nov: «2 مليون و200 الف» came back 2200200. The advertiser's text is then published as he wrote it
    (Laravel, on 503), so no amount he did not write reaches a buyer."""
    text = "فيلا للبيع المطلوب 2 مليون و 200 الف قابل التفاوض والمساحه 39 الف و 412 متر"
    model.answers["clean_listing"] = {"desc_clean": "فيلا للبيع بسعر 2200200 ريال", "features": []}
    refused = client.post("/agents/clean_listing", json={"type": "villa", "text": text})
    assert refused.status_code == 503 and "amount" in refused.text

    for same in ("بسعر 2.2 مليون ريال ومساحة 39412 م", "بسعر 2,200,000 ريال", "بسعر 2 مليون و200 ألف"):
        model.answers["clean_listing"] = {"desc_clean": f"فيلا للبيع {same}", "features": []}
        assert call(client, "clean_listing", {"type": "villa", "text": text})["output"]["desc_clean"].endswith(same)

    model.answers["clean_listing"] = {"desc_clean": "شقة بسعر 880,000 ريال", "features": []}
    sent = {"type": "apartment", "text": "شقة للبيع", "fields": {"price": 880000}}
    assert call(client, "clean_listing", sent)["output"]["desc_clean"] == "شقة بسعر 880,000 ريال", "the fields count"


def test_a_wording_that_swaps_the_price_and_the_area_is_refused(client: TestClient, model: ScriptedModel) -> None:
    """Each number is one the advertiser wrote, in the other's place: 880,000 m² at 150 riyals. The amounts guard
    alone passes it (both values are stated); what each is said to be must be what the text or fields say."""
    text = "شقة للبيع مساحتها 150 م بسعر 880 الف في النرجس"
    sent = {"type": "apartment", "text": text, "fields": {"price": 880000, "area_built": 150}}
    model.answers["clean_listing"] = {"desc_clean": "شقة مساحتها 880,000 م بسعر 150 ريال", "features": []}
    refused = client.post("/agents/clean_listing", json=sent)
    assert refused.status_code == 503 and "role" in refused.text

    model.answers["clean_listing"] = {"desc_clean": "شقة مساحتها 150 م² بسعر 880 ألف ريال", "features": []}
    assert call(client, "clean_listing", sent)["output"]["desc_clean"].startswith("شقة مساحتها 150")


def test_a_deed_the_advertiser_did_not_state_is_refused(client: TestClient, model: ScriptedModel) -> None:
    """«صك» is a claim a buyer relies on: in the wording or the features, only when his text or the deed field says
    it (a «بدون صك» he wrote may stay as he wrote it)."""
    sent = {"type": "apartment", "text": "شقة للبيع في النرجس 3 غرف وصالة"}
    for answer in (
        {"desc_clean": "شقة 3 غرف وصالة، الصك جاهز", "features": []},
        {"desc_clean": "شقة 3 غرف وصالة", "features": ["صك إلكتروني"]},
    ):
        model.answers["clean_listing"] = answer
        refused = client.post("/agents/clean_listing", json=sent)
        assert refused.status_code == 503 and "deed" in refused.text, answer

    model.answers["clean_listing"] = {"desc_clean": "شقة 3 غرف وصالة، الصك جاهز", "features": ["صك"]}
    with_deed = {**sent, "fields": {"deed": True}}
    assert call(client, "clean_listing", with_deed)["output"]["features"] == ["صك"], "the field says it"
    model.answers["clean_listing"] = {"desc_clean": "شقة 3 غرف بدون صك", "features": []}
    said = {**sent, "text": "شقة للبيع 3 غرف بدون صك"}
    assert call(client, "clean_listing", said)["output"]["desc_clean"] == "شقة 3 غرف بدون صك", "as he wrote it"


def test_a_deed_he_said_is_not_there_is_never_written_as_there(client: TestClient, model: ScriptedModel) -> None:
    """«بدون صك» is the opposite claim: a wording or a feature that says there is a deed is refused, and so is one
    that says there is none when he said there is (or the field does)."""
    none = {"type": "land", "text": "أرض للبيع بدون صك في الدرعية 600 م"}
    for answer in (
        {"desc_clean": "أرض بالدرعية 600 م، الصك جاهز", "features": []},
        {"desc_clean": "أرض بالدرعية 600 م", "features": ["صك"]},
    ):
        model.answers["clean_listing"] = answer
        refused = client.post("/agents/clean_listing", json=none)
        assert refused.status_code == 503 and "deed" in refused.text, answer
    model.answers["clean_listing"] = {"desc_clean": "أرض بالدرعية 600 م بدون صك", "features": []}
    assert call(client, "clean_listing", none)["output"]["desc_clean"].endswith("بدون صك"), "as he said it"

    model.answers["clean_listing"] = {"desc_clean": "شقة بالنرجس، لا يوجد صك", "features": []}
    deed = {"type": "apartment", "text": "شقة بالنرجس صك الكتروني جاهز"}
    refused = client.post("/agents/clean_listing", json=deed)
    assert refused.status_code == 503 and "deed" in refused.text, "none where he said there is one"
    field_no = {"type": "apartment", "text": "شقة بالنرجس 3 غرف", "fields": {"deed": False}}
    model.answers["clean_listing"] = {"desc_clean": "شقة بالنرجس 3 غرف، الصك غير جاهز", "features": []}
    assert call(client, "clean_listing", field_no)["output"]["desc_clean"].endswith("غير جاهز"), "the field says it"


def test_the_lab_reports_why_a_wording_was_refused_for_its_deed_and_no_word_of_it(
    client: TestClient, model: ScriptedModel, tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Owner, 21 Nov 2026: the reports go to a public repository — of a deed refusal they keep the case, where the
    claim was (the description or the features), why, and whether it affirmed or denied a deed. The two words around
    «صك», cut before the names were masked, kept a whole name; the wording now goes only to a private file
    (ZON_PRIVATE_REPORTS), redacted before it is cut. Production keeps nothing."""
    import asyncio

    from pydantic_ai.models.function import FunctionModel

    from zon_agents.lab.cases import Case
    from zon_agents.lab.runner import report, run_cases
    from zon_agents.llm import LLM
    from zon_agents.pipelines.text import DEED_REFUSALS, deed_refusal, redact
    from zon_agents.profiles import load_profile

    text = "أرض 750م للبيع، للتواصل 0551234567"
    model.answers["clean_listing"] = {"desc_clean": "أرض 750 م² للبيع، المالك محمد العتيبي صك جاهز", "features": []}
    refused = client.post("/agents/clean_listing", json={"type": "land", "text": text})
    assert refused.status_code == 503 and DEED_REFUSALS.get() is None, "production: refused, and nothing kept"

    llm = LLM(
        FunctionModel(model),
        model.prompts,
        profile=load_profile("qwen3.8-27b-fp8"),
        max_concurrency=2,
        budget_seconds=5,
    )
    case = Case(id="real-ad-011", agent="clean_listing", tags=[], input={"type": "land", "text": text},
                expect={"keep": [["750"]]})  # fmt: skip
    private = tmp_path / "private"
    monkeypatch.setenv("ZON_PRIVATE_REPORTS", str(private))
    outcomes = asyncio.run(run_cases(llm, [case], with_variants=False))
    [outcome] = outcomes
    assert outcome.error and "deed" in outcome.error
    assert outcome.refusal == {"source": "desc", "reason": "the text says nothing of a deed", "claim": "affirmed"}

    from datetime import UTC, datetime

    published = json.dumps(
        report(outcomes, [case], llm, started=datetime.now(UTC), seconds=1.0, gpu="A40", notes=None),
        ensure_ascii=False,
        default=str,
    )
    for word in ("محمد", "العتيبي", "المالك", "صك جاهز", "0551234567"):
        assert word not in published, f"«{word}» in the public report"
    [kept] = [json.loads(line) for line in (private / "deed-wordings.jsonl").read_text(encoding="utf-8").splitlines()]
    assert kept["case"] == "real-ad-011" and "محمد" not in kept["wording"] and "العتيبي" not in kept["wording"]
    assert "صك جاهز" in kept["wording"], "the private diagnosis keeps what was said of the deed"

    assert deed_refusal("أرض للبيع", ["لا يوجد صك"], "أرض للبيع، الصك جاهز", None) == {
        "source": "features",
        "reason": "the text says the opposite",
        "claim": "denied",
    }
    said = redact("تواصل مع أبو فهد على 0551234567 أو @zon_sa أو a@b.com، رقم الصك 310045218890، الرخصة 7200012345")
    assert all(x not in said for x in ("فهد", "0551234567", "zon_sa", "a@b.com", "310045218890", "7200012345")), said
