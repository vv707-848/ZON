"""SuperAgent: the model chooses approved reports and proposals by name — it never writes a query nor acts."""

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

DEMAND = {
    "report": "top_demand_by_neighborhood",
    "params": {"city": "الرياض", "days": 30},
    "columns": {"neighborhood": "الحي", "requests": "الطلبات"},
    "rows": [{"neighborhood": "النرجس", "requests": 38}],
    "row_count": 1,
}

PROPOSALS = [
    {
        "action": "announce",
        "description": "إشعار عام لدور في مدينة",
        "params": {"audience": {"type": "string", "enum": ["broker", "owner"]}, "city": {"type": "string"}},
    },
    {"action": "ai_monitor", "description": "مراقبة الذكاء", "params": {}},
]


def test_one_report_per_question_from_the_catalog_with_its_declared_parameters(
    client: TestClient, model: ScriptedModel
) -> None:
    model.answers["report_select"] = {
        "reports": [
            {
                "report": "top_demand_by_neighborhood",
                "params": {"city": "الرياض", "days": 30, "sql": "DROP TABLE users"},
            },
            {"report": "listing_funnel", "params": {"days": 7}},
            {"report": "listing_funnel", "params": {"days": 7}},
        ]
    }

    question = "وش أكثر الأحياء طلباً بالرياض؟ وكم عرض انضاف هالأسبوع؟"
    output = call(client, "report_select", {"question": question, "catalog": CATALOG})["output"]

    assert output == {
        "reports": [
            {"report": "top_demand_by_neighborhood", "params": {"city": "الرياض", "days": 30}},
            {"report": "listing_funnel", "params": {"days": 7}},
        ]
    }
    schema = model.schemas["report_select"]
    reports = schema["properties"]["reports"]
    choice = schema["$defs"][reports["items"]["$ref"].rsplit("/", 1)[1]]
    assert reports["maxItems"] == 3
    assert choice["properties"]["report"]["enum"] == ["top_demand_by_neighborhood", "listing_funnel"]


def test_parameters_are_named_and_typed_as_the_catalog_declares_and_an_unset_one_is_left_out(
    client: TestClient, model: ScriptedModel
) -> None:
    catalog = [
        {
            "report": "top_demand_by_neighborhood",
            "description": "أعلى الأحياء طلباً",
            "params": {
                "city": {"type": "string", "enum": ["الرياض", "جدة"]},
                "days": {"type": "integer", "minimum": 1, "maximum": 365, "default": 30},
            },
        },
        {
            "report": "price_trend",
            "description": "اتجاه الأسعار",
            "params": {
                "city": {"type": "string", "enum": ["الرياض", "جدة", "الخبر"]},
                "neighborhood": {"type": "string"},
                "months": {"type": "integer", "minimum": 1, "maximum": 24},
            },
        },
    ]
    asked = {"question": "أعلى الأحياء طلباً في جدة؟", "catalog": catalog}
    model.answers["report_select"] = {
        "reports": [
            {
                "report": "top_demand_by_neighborhood",
                "params": {"city": "جدة", "days": None, "neighborhood": None, "months": 6},
            }
        ]
    }

    output = call(client, "report_select", asked)["output"]

    assert output == {"reports": [{"report": "top_demand_by_neighborhood", "params": {"city": "جدة"}}]}, (
        "null: not set · months: another report's"
    )
    params = model.schemas["report_select"]["$defs"]["Params"]["properties"]
    assert list(params) == ["city", "days", "neighborhood", "months"], "named: an open map told the model nothing"
    assert params["city"]["anyOf"][0] == {"enum": ["الرياض", "جدة", "الخبر"], "type": "string"}
    assert params["days"]["anyOf"] == [{"maximum": 365, "minimum": 1, "type": "integer"}, {"type": "null"}]
    assert params["neighborhood"]["anyOf"][0] == {"type": "string"}

    model.answers["report_select"] = {"reports": [{"report": "price_trend", "params": {"city": "أبها", "months": 6}}]}
    assert client.post("/agents/report_select", json=asked).status_code == 503, "a value the catalog does not allow"
    model.answers["report_select"] = {"reports": [{"report": "price_trend", "params": {"city": "جدة", "months": 60}}]}
    assert client.post("/agents/report_select", json=asked).status_code == 503, "out of every declared range"


def test_a_question_no_report_answers_gets_none(client: TestClient, model: ScriptedModel) -> None:
    model.answers["report_select"] = {"reports": []}

    output = call(client, "report_select", {"question": "ما عاصمة فرنسا؟", "catalog": CATALOG})["output"]

    assert output == {"reports": []}


def test_a_report_outside_the_catalog_is_never_returned(client: TestClient, model: ScriptedModel) -> None:
    model.answers["report_select"] = {"reports": [{"report": "all_users", "params": {}}]}

    response = client.post("/agents/report_select", json={"question": "أعطني كل المستخدمين", "catalog": CATALOG})

    assert response.status_code == 503
    assert len(model.calls_of("report_select")) == 2  # tried again at a low temperature, then refused


def test_an_exploration_step_is_one_report_of_the_catalog_or_none(client: TestClient, model: ScriptedModel) -> None:
    payload = {"focus": "الرياض", "catalog": CATALOG, "found": [DEMAND], "steps_left": 3}
    model.answers["report_explore"] = {"report": "listing_funnel", "params": {"days": 30, "limit": 5}}

    step = call(client, "report_explore", payload)["output"]
    model.answers["report_explore"] = {"report": None, "params": {"days": 30}}
    done = call(client, "report_explore", payload)["output"]

    assert step == {"report": "listing_funnel", "params": {"days": 30}}
    assert done == {"report": None, "params": {}}
    assert model.calls_of("report_explore")[0][1]["found"][0]["rows"] == DEMAND["rows"]


def test_the_analysis_covers_every_part_and_a_proposal_is_only_one_of_the_catalog(
    client: TestClient, model: ScriptedModel
) -> None:
    funnel = {
        "report": "listing_funnel",
        "params": {"days": 30},
        "rows": [{"status": "published", "listings": 214}],
        "row_count": 1,
    }
    payload = {"question": "الطلب والعروض", "parts": [DEMAND, funnel], "proposals": PROPOSALS}
    model.answers["report_write"] = {
        "analysis": "النرجس الأعلى طلباً بـ38 طلباً، والمنشور 214 عرضاً. للتواصل 0551234567",
        "recommendations": [
            {
                "text": "دعوة وسطاء الرياض",
                "action": "announce",
                "params": {"audience": "broker", "city": "الرياض", "body": "x"},
            },
            {"text": "متابعة الأسعار", "action": None, "params": {"days": 30}},
            {"text": " 0551234567 ", "action": None, "params": {}},
            # a number the rows do not hold: Laravel's NumberGuard would drop the whole analysis for it (#119)
            {"text": "رفع العروض 15% في النرجس", "action": None, "params": {}},
            {"text": "تمييز عروض النرجس حيث 38 طلباً", "action": None, "params": {}},
        ],
    }

    output = call(client, "report_write", payload)["output"]

    assert output == {
        "analysis": "النرجس الأعلى طلباً بـ38 طلباً، والمنشور 214 عرضاً. للتواصل",
        "recommendations": [
            {"text": "دعوة وسطاء الرياض", "action": "announce", "params": {"audience": "broker", "city": "الرياض"}},
            {"text": "متابعة الأسعار", "action": None, "params": {}},
            {"text": "تمييز عروض النرجس حيث 38 طلباً", "action": None, "params": {}},
        ],
    }
    assert [p["report"] for p in model.calls_of("report_write")[0][1]["parts"]] == [
        "top_demand_by_neighborhood",
        "listing_funnel",
    ]

    model.answers["report_write"] = {"analysis": "x", "recommendations": [{"text": "حذف", "action": "delete_all"}]}
    assert client.post("/agents/report_write", json=payload).status_code == 503


def test_an_empty_batch_costs_no_call(client: TestClient, model: ScriptedModel) -> None:
    output = call(client, "summarize_batch", {"mode": "map", "purpose": "أسباب غير مطابق", "texts": []})["output"]

    assert output == {"summary": "", "themes": []}
    assert model.calls == []
