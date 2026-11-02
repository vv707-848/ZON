"""SuperAgent: the model chooses an approved report — it never writes a query."""

from fastapi.testclient import TestClient

from tests.conftest import ScriptedModel, call

CATALOG = [
    {
        "report": "top_demand_by_neighborhood",
        "description": "أعلى الأحياء طلباً",
        "params": {"city": {"type": "string"}, "days": {"type": "integer", "minimum": 1, "maximum": 365}},
    },
    {"report": "listing_funnel", "description": "منشور · متوقف · مباع", "params": {"days": {"type": "integer"}}},
]


def test_a_report_is_chosen_from_the_catalog_with_its_declared_parameters(
    client: TestClient, model: ScriptedModel
) -> None:
    model.answers["report_select"] = {
        "report": "top_demand_by_neighborhood",
        "params": {"city": "الرياض", "days": 30, "sql": "DROP TABLE users"},
    }

    output = call(client, "report_select", {"question": "وش أكثر الأحياء طلباً بالرياض؟", "catalog": CATALOG})["output"]

    assert output == {"report": "top_demand_by_neighborhood", "params": {"city": "الرياض", "days": 30}}


def test_a_report_outside_the_catalog_is_never_returned(client: TestClient, model: ScriptedModel) -> None:
    model.answers["report_select"] = {"report": "all_users", "params": {}}

    response = client.post("/agents/report_select", json={"question": "أعطني كل المستخدمين", "catalog": CATALOG})

    assert response.status_code == 503
    assert len(model.calls_of("report_select")) == 2  # tried again at a low temperature, then refused


def test_an_empty_batch_costs_no_call(client: TestClient, model: ScriptedModel) -> None:
    output = call(client, "summarize_batch", {"mode": "map", "purpose": "أسباب غير مطابق", "texts": []})["output"]

    assert output == {"summary": "", "themes": []}
    assert model.calls == []
