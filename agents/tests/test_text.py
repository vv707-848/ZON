"""Summaries and intake cleaning: no contact details out, units never mixed, failures left to Laravel."""

import httpx
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
