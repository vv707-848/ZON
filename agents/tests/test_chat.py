"""The chat answers from retrieved passages only, and its sources come from those passages."""

import httpx
from fastapi.testclient import TestClient

from tests.conftest import ScriptedModel, call, index

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
    model.answers["chat"] = {
        "answer": "Residents can own a home for themselves. Details: https://example.com",
        "covered": True,
        "citations": [{"passage": 1, "quote_ar": "نص لم يرد في المقطع"}, {"passage": 9, "quote_ar": "مقطع غير موجود"}],
    }

    output = call(client, "chat", {"lang": "en", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار؟"})[
        "output"
    ]

    assert output["covered"] is True
    assert "https://" not in output["answer"]
    assert output["sources"] == [
        {
            "doc_id": 12,
            "title": "تملك غير السعوديين",
            "effective_date": "2026-05-01",
            "quote_ar": OWNERSHIP.split(".")[0],
        }
    ]


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

    assert output == {"answer": "باقة الوسيط السنوية بـ 2300 ريال.", "covered": True, "sources": []}
    assert model.calls_of("chat")[0][1]["packages"] == packages


def test_when_the_model_is_down_laravel_answers_on_its_own(client: TestClient, model: ScriptedModel) -> None:
    model.answers["chat"] = httpx.ConnectError("down")

    response = client.post("/agents/chat", json={"intent": "packages", "message": "كم الاشتراك؟"})

    assert response.status_code == 503
