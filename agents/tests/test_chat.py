"""The chat answers from retrieved passages only, and its sources come from those passages."""

import httpx
import pytest
from fastapi.testclient import TestClient

from tests.conftest import ScriptedModel, call, index
from zon_agents.config import Settings
from zon_agents.profiles import load_profile
from zon_agents.tools.retrieval import Retrieval

OWNERSHIP = (
    "يحق لغير السعودي المقيم إقامة نظامية تملك عقار لسكنه الخاص بعد الحصول على موافقة الجهات المختصة. "
    "ولا يجوز التملك داخل حدود مكة المكرمة والمدينة المنورة إلا وفق الأنظمة."
)


def _document(client: TestClient, next_review: str = "2099-01-01") -> None:
    document = {
        "title": "تملك غير السعوديين",
        "category": "ownership",
        "version": "3",
        "effective_date": "2026-05-01",
        "source": "الهيئة العامة للعقار",
        "next_review": next_review,
        "body": OWNERSHIP,
    }
    index(client, "kb_document", 12, document)


def test_a_covered_question_cites_the_real_passage(client: TestClient, model: ScriptedModel) -> None:
    _document(client)
    quoted = "يحقُّ لغير السعودي المقيم  إقامة نظامية تملك عقار لسكنه الخاص"  # its diacritics and spacing aside
    model.answers["chat"] = {
        "answer": "Residents can own a home for themselves. Details: https://example.com",
        "covered": True,
        "citations": [
            {"passage": 1, "quote_ar": "نص لم يرد في المقطع"},
            {"passage": 9, "quote_ar": "مقطع غير موجود"},
            {"passage": 1, "quote_ar": quoted},
        ],
    }

    output = call(client, "chat", {"lang": "en", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار؟"})[
        "output"
    ]

    assert output["covered"] is True
    assert "https://" not in output["answer"]
    assert output["sources"] == [
        {"doc_id": 12, "title": "تملك غير السعوديين", "effective_date": "2026-05-01", "quote_ar": quoted}
    ]
    profile = load_profile(Settings.model_fields["llm_profile"].default)
    assert model.settings[-1]["temperature"] == profile.sampling["summary"].temperature, "a knowledge answer runs cool"


def test_a_citation_whose_quote_the_passage_does_not_carry_is_no_source(
    client: TestClient, model: ScriptedModel
) -> None:
    _document(client)
    model.answers["chat"] = {
        "answer": "يتملك غير السعودي بلا قيد.",
        "covered": True,
        "citations": [{"passage": 1, "quote_ar": "يتملك غير السعودي بلا قيد"}],
    }

    output = call(client, "chat", {"lang": "ar", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار؟"})[
        "output"
    ]

    assert output["covered"] is False and output["sources"] == []
    assert "بلا قيد" not in output["answer"], "the passage's first line is not shown as if it carried the answer"


def test_without_a_close_passage_it_says_so_without_calling_the_model(client: TestClient, model: ScriptedModel) -> None:
    _document(client)

    output = call(client, "chat", {"lang": "ar", "intent": "knowledge", "message": "كم سعر المتر في جدة اليوم"})[
        "output"
    ]

    assert output["covered"] is False
    assert output["sources"] == []
    assert model.calls_of("chat") == []


def test_a_document_past_its_review_date_is_not_retrieved(client: TestClient, model: ScriptedModel) -> None:
    _document(client, next_review="2020-01-01")

    output = call(client, "chat", {"intent": "knowledge", "message": "يحق لغير السعودي تملك عقار"})["output"]

    assert output["covered"] is False
    assert model.calls == []


def test_covered_without_any_source_is_not_trusted(client: TestClient, model: ScriptedModel) -> None:
    _document(client)
    model.answers["chat"] = {"answer": "نعم يجوز مطلقاً", "covered": True, "citations": []}

    output = call(client, "chat", {"lang": "ar", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار"})[
        "output"
    ]

    assert len(model.calls_of("chat")) == 1  # the passage was found and the model answered
    assert output["covered"] is False
    assert "مطلقاً" not in output["answer"]


def test_package_questions_get_the_platforms_own_figures(client: TestClient, model: ScriptedModel) -> None:
    model.answers["chat"] = {"answer": "باقة الوسيط السنوية بـ 2300 ريال.", "covered": True}
    packages = [{"name": "الوسيط السنوية", "price": 2300, "period": "سنوي", "features": ["15 تمييزاً"]}]

    output = call(client, "chat", {"intent": "packages", "message": "كم الاشتراك؟", "packages": packages})["output"]

    assert output == {
        "answer": "باقة الوسيط السنوية بـ 2300 ريال.",
        "covered": True,
        "sources": [],
        "intent": "packages",
    }
    assert model.calls_of("chat")[0][1]["packages"] == packages


def test_a_language_no_rule_reads_is_answered_in_it_and_the_model_names_the_intent(
    client: TestClient, model: ScriptedModel, monkeypatch: pytest.MonkeyPatch
) -> None:
    _document(client)
    # bge-m3 matches a French question to an Arabic passage; the tests' bag of words does not: it reads its meaning
    knowledge = Retrieval.knowledge
    monkeypatch.setattr(
        Retrieval, "knowledge", lambda self, question, today: knowledge(self, "يحق لغير السعودي تملك عقار", today)
    )
    quoted = "يحق لغير السعودي المقيم إقامة نظامية تملك عقار لسكنه الخاص"
    model.answers["chat"] = {
        "answer": "Oui : un résident étranger peut posséder un logement pour lui-même.",
        "covered": True,
        "citations": [{"passage": 1, "quote_ar": quoted}],
        "intent": "knowledge",
    }
    asked = {"lang": "xx", "intent": "auto", "message": "Un étranger peut-il posséder un bien en Arabie ?"}

    output = call(client, "chat", {**asked, "packages": [{"name": "الباحث", "price": 199}]})["output"]

    assert output["intent"] == "knowledge" and output["covered"] is True
    assert output["answer"].startswith("Oui") and output["sources"][0]["quote_ar"] == quoted, (
        "the Arabic source under it"
    )
    sent = model.calls_of("chat")[0][1]
    assert sent["passages"] and sent["packages"], "both, for the model to choose from"

    model.answers["chat"] = {
        "answer": "Je suis l'assistant immobilier de Zon.",
        "covered": True,
        "intent": "out_of_scope",
    }
    outside = call(client, "chat", {**asked, "message": "Qui gagnera le match ce soir ?"})["output"]
    assert outside == {
        "answer": "Je suis l'assistant immobilier de Zon.",
        "covered": False,
        "sources": [],
        "intent": "out_of_scope",
    }

    model.answers["chat"] = {
        "answer": "Oui, sans aucune limite.",
        "covered": True,
        "citations": [],
        "intent": "knowledge",
    }
    unproven = call(client, "chat", {**asked, "message": "Un étranger peut-il tout acheter ?"})["output"]
    assert unproven["covered"] is False and "limite" not in unproven["answer"], "the fixed text, not an unsourced claim"


def test_when_the_model_is_down_laravel_answers_on_its_own(client: TestClient, model: ScriptedModel) -> None:
    model.answers["chat"] = httpx.ConnectError("down")

    response = client.post("/agents/chat", json={"intent": "packages", "message": "كم الاشتراك؟"})

    assert response.status_code == 503
