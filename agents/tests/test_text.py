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
