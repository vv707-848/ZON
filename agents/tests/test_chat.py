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
        "citations": [{"passage": 9, "quote_ar": "مقطع غير موجود"}, {"passage": 1, "quote_ar": quoted}],
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


@pytest.mark.parametrize("intent", ["semantic_search", "matching", "search_request", "decision", "packages"])
def test_only_a_knowledge_answer_can_be_not_covered(client: TestClient, model: ScriptedModel, intent: str) -> None:
    """With no passage to quote, a 2-bit model says `covered: false` for a service it rightly points to (#119: four
    of the chat's eighteen answers): the pipeline then put «not in the library» over the right answer."""
    said = "هلا والله، أبشر بسعدك. عندك طريقتين: «البحث الدلالي» و«مساعد المطابقة»."
    model.answers["chat"] = {"answer": said, "covered": False, "citations": []}

    output = call(client, "chat", {"intent": intent, "role": "seeker", "message": "ابي شقة في جدة"})["output"]

    assert output == {"answer": said, "covered": True, "sources": [], "intent": intent}


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


def test_the_model_knows_the_askers_role_for_the_services_he_has(client: TestClient, model: ScriptedModel) -> None:
    model.answers["chat"] = {"answer": "ابحث في «البحث الدلالي» في العروض وفي طلبات الباحثين.", "covered": True}

    call(client, "chat", {"intent": "semantic_search", "role": "broker", "message": "عندي عميل يبي فيلا"})

    assert model.calls_of("chat")[0][1]["role"] == "broker", "a broker is not sent to the seekers' matching"


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


def test_an_answer_in_another_script_than_the_message_is_asked_once_more_in_its_language(
    client: TestClient, model: ScriptedModel
) -> None:
    """A40, 21 Nov: English, Turkish and Russian questions answered in Arabic. The model is told the language
    (`reply_in`), and an answer in another script is asked once more; one in the right script is kept as it is."""
    arabic = {"answer": "باقة الباحث بـ 199 ريال شهرياً.", "covered": True}
    english = {"answer": "The seeker plan is 199 riyals a month.", "covered": True}
    model.answers["chat"] = [arabic, english]
    asked = {"lang": "en", "intent": "packages", "message": "How much is the seeker plan?"}

    output = call(client, "chat", {**asked, "packages": [{"name": "الباحث", "price": 199}]})["output"]

    assert output["answer"] == english["answer"]
    first, second = (c[1] for c in model.calls_of("chat"))
    assert first["reply_in"] == "English" and "another language" in second["reply_in"]

    model.answers["chat"] = [{"answer": "Тариф стоит 199 риалов.", "covered": True, "intent": "packages"}]
    russian = {
        "lang": "xx",
        "intent": "auto",
        "message": "Сколько стоит тариф?",
        "packages": [{"name": "x", "price": 199}],
    }
    assert call(client, "chat", russian)["output"]["answer"].startswith("Тариф"), "the right script: one call"
    assert model.calls_of("chat")[-1][1]["reply_in"] == "русский" and len(model.calls_of("chat")) == 3


def test_not_covered_in_a_language_no_rule_reads_is_said_in_its_script_when_known(
    client: TestClient, model: ScriptedModel
) -> None:
    model.answers["chat"] = {"answer": "Да", "covered": False, "citations": [], "intent": "knowledge"}
    output = call(client, "chat", {"lang": "xx", "intent": "auto", "message": "Можно ли удалить аккаунт?"})["output"]
    assert output["covered"] is False and output["answer"].startswith("Библиотека Zon")


def test_a_knowledge_answer_cites_two_passages_at_most_each_with_a_short_quote(
    client: TestClient, model: ScriptedModel
) -> None:
    """A40, 21 Nov: both chat timeouts were knowledge answers, whose slowest part is the quotes copied letter by
    letter. The schema the engine decodes to holds them to two, of 200 letters."""
    _document(client)
    model.answers["chat"] = {"answer": "نعم.", "covered": False, "citations": []}
    call(client, "chat", {"lang": "ar", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار؟"})
    citations = model.schemas["chat"]["properties"]["citations"]
    assert citations["maxItems"] == 2
    quote = (
        model.schemas["chat"]["$defs"]["Citation"]["properties"]["quote_ar"]
        if "$defs" in model.schemas["chat"]
        else (citations["items"]["properties"]["quote_ar"])
    )
    assert quote["maxLength"] == 200


def test_a_knowledge_question_out_of_time_is_answered_by_the_documented_excerpt_and_counted_as_failed(
    client: TestClient, model: ScriptedModel
) -> None:
    """The owner (21 Nov): the documented excerpt is the basis of the answer. With the model out of time, the
    closest passage's first sentence word for word with its source — degraded, so the lab counts it a failure."""
    _document(client)
    model.answers["chat"] = httpx.ReadTimeout("slow")
    body = call(client, "chat", {"lang": "ar", "intent": "knowledge", "message": "يحق لغير السعودي تملك عقار؟"})

    assert body["degraded"] is True
    output = body["output"]
    quote = output["sources"][0]["quote_ar"]
    assert output["covered"] is True and output["answer"].startswith("بحسب «") and output["answer"].endswith(quote)
    assert quote and quote in " ".join(OWNERSHIP.split()), "word for word"
