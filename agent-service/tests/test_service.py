from uuid import uuid4

from fastapi.testclient import TestClient

from app.main import app
from app.schemas.research import ResearchRequest


PAYLOAD = {
    "research_run_id": str(uuid4()),
    "nonprofit": {"name": "FutureForge Youth", "website": "https://futureforge.example.org"},
    "campaign": {"title": "Three new robotics labs", "description": "Expand free robotics programs into three schools.", "goal_amount": 75000, "geography": "Philadelphia", "board_members": ["Sarah Johnson"]},
}


def test_fixture_pipeline_returns_sourced_typed_prospects(monkeypatch):
    monkeypatch.setenv("DEMO_MODE", "true")
    result = __import__("asyncio").run(__import__("app.main", fromlist=["pipeline"]).pipeline.run(ResearchRequest.model_validate(PAYLOAD)))
    assert result.graph == ["campaign_analyst", "evidence_researcher", "people_researcher", "synthesizer"]
    assert result.prospects[0].score_signals.cause_alignment == .93
    assert all(claim.sources for prospect in result.prospects for claim in prospect.claims)


def test_api_requires_shared_secret(monkeypatch):
    monkeypatch.setenv("AGENT_SERVICE_SECRET", "test-secret")
    monkeypatch.setenv("DEMO_MODE", "true")
    client = TestClient(app)
    assert client.post("/research", json=PAYLOAD).status_code == 401
    response = client.post("/research", json=PAYLOAD, headers={"X-Agent-Secret": "test-secret"})
    assert response.status_code == 200
    assert response.json()["mode"] == "demo"


def test_bad_score_signal_is_rejected():
    from app.schemas.research import ScoreSignals
    import pytest
    with pytest.raises(ValueError):
        ScoreSignals(cause_alignment=1.1, historical_giving=0, geographic_fit=0, grant_size_fit=0, recency=0, relationship_strength=0)


def test_nullable_profile_lists_are_normalized_before_research():
    payload = {
        **PAYLOAD,
        "nonprofit": {
            **PAYLOAD["nonprofit"],
            "desired_funding_categories": None,
            "needs": None,
            "keywords": None,
        },
    }

    request = ResearchRequest.model_validate(payload)

    assert request.nonprofit.desired_funding_categories == []
    assert request.nonprofit.needs == []
    assert request.nonprofit.keywords == []


def test_live_pipeline_builds_a_real_bounded_strands_graph(monkeypatch):
    monkeypatch.setenv("AWS_EC2_METADATA_DISABLED", "true")
    from app.main import pipeline
    graph = pipeline.build_strands_graph()
    assert len(graph.nodes) == 4
    assert {
        (edge.from_node.node_id, edge.to_node.node_id)
        for edge in graph.edges
    } == {
        ("campaign_analyst", "evidence_researcher"),
        ("campaign_analyst", "people_researcher"),
        ("evidence_researcher", "synthesizer"),
        ("people_researcher", "synthesizer"),
    }
    assert {node.node_id for node in graph.entry_points} == {"campaign_analyst"}


def test_demo_results_adapt_to_nonprofit_mission_geography_and_goal(monkeypatch):
    monkeypatch.setenv("DEMO_MODE", "true")
    from app.main import pipeline
    food_payload = {
        **PAYLOAD,
        "research_run_id": str(uuid4()),
        "nonprofit": {"name": "Community Pantry", "website": "https://pantry.example.org"},
        "campaign": {"title": "Fresh food access", "description": "Provide nutritious meals and expand our neighborhood food pantry.", "goal_amount": 30000, "geography": "Detroit, MI", "board_members": []},
    }
    food_result = __import__("asyncio").run(pipeline.run(ResearchRequest.model_validate(food_payload)))
    stem_result = __import__("asyncio").run(pipeline.run(ResearchRequest.model_validate(PAYLOAD)))

    assert food_result.prospects[0].name == "Harvest Equity Foundation"
    assert stem_result.prospects[0].name == "Miller Family Foundation"
    assert "Detroit, MI" in food_result.events[0].message
    assert food_result.prospects[0].recommended_ask_max != stem_result.prospects[0].recommended_ask_max
    assert food_result.prospects[0].relationships == []


def test_live_readiness_fails_without_aws_credentials(monkeypatch):
    monkeypatch.setenv("DEMO_MODE", "false")
    monkeypatch.delenv("AWS_PROFILE", raising=False)
    monkeypatch.delenv("AWS_ACCESS_KEY_ID", raising=False)
    monkeypatch.delenv("AWS_SECRET_ACCESS_KEY", raising=False)

    response = TestClient(app).get("/ready")

    assert response.status_code == 503
    assert response.json()["detail"]["missing"] == ["AWS credentials"]


def test_relevance_guard_rejects_generic_funders_without_campaign_evidence():
    from app.graph.pipeline import ResearchPipeline
    from app.tools.fixtures import prospects

    stem_request = ResearchRequest.model_validate(PAYLOAD)
    water_request = ResearchRequest.model_validate({
        **PAYLOAD,
        "campaign": {
            "title": "Drill five wells",
            "description": "Provide safe drinking water through new boreholes.",
            "goal_amount": 10000,
            "geography": "Malawi",
            "board_members": [],
        },
    })

    assert ResearchPipeline._relevant_prospects(stem_request, prospects(stem_request))
    assert ResearchPipeline._relevant_prospects(water_request, prospects(stem_request)) == []


def test_relevance_guard_rejects_placeholder_and_nonprofit_names():
    from app.graph.pipeline import ResearchPipeline
    from app.tools.fixtures import prospects

    request = ResearchRequest.model_validate(PAYLOAD)
    candidates = prospects(request)
    candidates[0].name = "Potential Funder 1"
    candidates[1].name = "FutureForge Youth"

    accepted = ResearchPipeline._relevant_prospects(request, candidates)

    assert [prospect.name for prospect in accepted] == ["Northstar Corporate Giving"]


def test_specific_mental_health_campaign_rejects_generic_health_evidence():
    from app.graph.pipeline import ResearchPipeline
    from app.tools.fixtures import prospects

    request = ResearchRequest.model_validate({
        **PAYLOAD,
        "campaign": {
            "title": "Build a mental health clubhouse",
            "description": "Psychiatric recovery and employment services for adults with serious mental illness.",
            "goal_amount": 50000,
            "geography": "Vermont",
            "board_members": [],
        },
    })
    candidates = prospects(request)
    candidates[0].name = "Generic Health Agency"
    candidates[0].claims[0].claim = "Supports infectious disease prevention."
    candidates[0].claims[0].sources[0].title = "Public health programs"
    candidates[0].claims[0].sources[0].excerpt_or_locator = "Funds public medical clinics and infectious disease prevention programs."
    candidates[0].claims = candidates[0].claims[:1]
    candidates[0].grants = []
    candidates[1].name = "Mental Health Foundation"
    candidates[1].claims[0].claim = "Supports psychiatric recovery."
    candidates[1].claims[0].sources[0].title = "Mental health recovery grants"
    candidates[1].claims[0].sources[0].excerpt_or_locator = "Funds psychiatric recovery and mental illness community services."
    candidates[1].claims = candidates[1].claims[:1]
    candidates[1].grants = []

    accepted = ResearchPipeline._relevant_prospects(request, candidates[:2])

    assert [prospect.name for prospect in accepted] == ["Mental Health Foundation"]
