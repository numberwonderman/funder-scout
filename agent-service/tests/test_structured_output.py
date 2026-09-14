import asyncio
import json
from types import SimpleNamespace
from uuid import uuid4

import pytest

from app.graph.pipeline import ResearchPipeline
from app.schemas.research import ResearchRequest
from app.structured_output import StructuredResponseError, log_stage, parse_candidate_envelope, response_fingerprint


REQUEST = ResearchRequest.model_validate({
    "research_run_id": str(uuid4()),
    "nonprofit": {"name": "Water Partners", "website": "https://water.example.org"},
    "campaign": {
        "title": "Drill five wells",
        "description": "Safe drinking water and sanitation",
        "goal_amount": 10000,
        "geography": "Malawi",
        "board_members": [],
    },
})


def valid_payload(name: str = "Rotary Water Fund", excerpt: str = "Funds safe water wells in Malawi") -> dict:
    return {
        "prospects": [{
            "name": name,
            "funder_type": "Public charity",
            "ein": None,
            "summary": "Candidate summary",
            "confidence": "high",
            "sources": [{"id": "s1", "title": "Water grants", "url": "https://example.org/grants", "source_type": "official", "excerpt_or_locator": excerpt}],
            "claims": [{"key": "cause", "claim": "Funds safe-water projects.", "confidence": "high", "source_ids": ["s1"]}],
            "grants": [{"recipient": "A water charity", "purpose": "Drinking water well", "amount": 8000, "year": 2026, "source_id": "s1"}],
            "people": [],
        }]
    }


class FakeAgentResult:
    stop_reason = "end_turn"

    def __init__(self, raw: str) -> None:
        self.raw = raw

    def __str__(self) -> str:
        return self.raw


def fake_graph_result(raw: str):
    agent_result = FakeAgentResult(raw)
    node_result = SimpleNamespace(execution_time=10, get_agent_results=lambda: [agent_result])
    node = SimpleNamespace(node_id="synthesizer")
    return SimpleNamespace(results={"synthesizer": node_result}, execution_order=[node])


def valid_context() -> dict:
    return {
        "nonprofit_site_pages": [],
        "fetched_discovery_pages": [{"source_id": "s1", "url": "https://example.org/grants", "title": "Canonical grants page", "source_type": "public_web", "text": "Water grants. Funds safe water wells in Malawi"}],
        "provider_evidence_pages": [],
        "nonprofit_registry": {},
        "discovery_provider": "test",
        "evidence_providers": [],
    }


def mock_context(monkeypatch, pipeline):
    monkeypatch.setattr(pipeline, "_bounded_research_context", lambda request: asyncio.sleep(0, result=valid_context()))


def test_malformed_json_is_rejected():
    with pytest.raises(StructuredResponseError, match="Could not parse") as error:
        parse_candidate_envelope('{"prospects": [')
    assert error.value.kind == "malformed_json"


def test_prose_and_code_fences_around_json_are_accepted():
    raw = "Here is the result:\n```json\n" + json.dumps(valid_payload()) + "\n```\nDone."
    assert parse_candidate_envelope(raw).prospects[0].name == "Rotary Water Fund"


def test_truncated_output_is_rejected():
    raw = json.dumps(valid_payload())[:-20]
    with pytest.raises(StructuredResponseError) as error:
        parse_candidate_envelope(raw)
    assert error.value.kind == "malformed_json"


def test_schema_violation_is_rejected_with_safe_field_paths():
    payload = valid_payload()
    del payload["prospects"][0]["claims"][0]["key"]
    with pytest.raises(StructuredResponseError) as error:
        parse_candidate_envelope(json.dumps(payload))
    assert error.value.kind == "schema_violation"
    assert any(item["location"].endswith("key") for item in error.value.validation_errors)


def test_unresolved_source_reference_is_rejected():
    payload = valid_payload()
    payload["prospects"][0]["grants"][0]["source_id"] = "missing-source"
    with pytest.raises(StructuredResponseError) as error:
        parse_candidate_envelope(json.dumps(payload))
    assert error.value.kind == "schema_violation"


def test_duplicate_json_keys_are_rejected():
    raw = '{"prospects":[],"prospects":[]}'
    with pytest.raises(StructuredResponseError, match="duplicate JSON key"):
        parse_candidate_envelope(raw)


def test_generic_source_locator_is_too_weak():
    payload = valid_payload()
    payload["prospects"][0]["sources"][0]["excerpt_or_locator"] = "About Us"
    envelope = parse_candidate_envelope(json.dumps(payload))
    assert ResearchPipeline._verified_candidates(valid_context(), envelope) == []


def test_diagnostics_log_only_fingerprint_not_raw_content(caplog):
    secret_text = '{"private":"do-not-log"}'
    fingerprint = response_fingerprint(secret_text)
    with caplog.at_level("WARNING"):
        log_stage("test", 0, **fingerprint)
    assert "do-not-log" not in caplog.text
    assert fingerprint["sha256"] in caplog.text


@pytest.mark.asyncio
async def test_valid_output_is_promoted_scored_and_returned(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("DEMO_MODE", "false")
    mock_context(monkeypatch, pipeline)
    monkeypatch.setattr(pipeline, "_invoke_graph", lambda task, request, on_progress=None: asyncio.sleep(0, result=fake_graph_result(json.dumps(valid_payload()))))
    result = await pipeline.run(REQUEST)
    assert [item.name for item in result.prospects] == ["Rotary Water Fund"]
    assert result.prospects[0].score_signals.cause_alignment == 1
    assert result.prospects[0].relationships == []
    assert result.prospects[0].claims[0].sources[0].url.host == "example.org"


@pytest.mark.asyncio
async def test_irrelevant_organization_fails_closed(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("DEMO_MODE", "false")
    mock_context(monkeypatch, pipeline)
    payload = valid_payload("Ford Foundation", "General arts and culture giving")
    payload["prospects"][0]["sources"][0]["title"] = "Arts grants"
    payload["prospects"][0]["claims"][0]["claim"] = "Funds museum exhibitions."
    payload["prospects"][0]["grants"] = []
    raw = json.dumps(payload)
    monkeypatch.setattr(pipeline, "_invoke_graph", lambda task, request, on_progress=None: asyncio.sleep(0, result=fake_graph_result(raw)))
    with pytest.raises(RuntimeError, match="failed closed"):
        await pipeline.run(REQUEST)


@pytest.mark.asyncio
async def test_malformed_output_gets_exactly_one_bounded_repair(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("DEMO_MODE", "false")
    mock_context(monkeypatch, pipeline)
    monkeypatch.setattr(pipeline, "_invoke_graph", lambda task, request, on_progress=None: asyncio.sleep(0, result=fake_graph_result("not-json")))
    calls = 0

    async def repair(raw, error):
        nonlocal calls
        calls += 1
        return json.dumps(valid_payload())

    monkeypatch.setattr(pipeline, "_repair", repair)
    result = await pipeline.run(REQUEST)
    assert calls == 1
    assert len(result.prospects) == 1


@pytest.mark.asyncio
async def test_repair_stall_times_out(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("RESEARCH_REPAIR_TIMEOUT_SECONDS", "0.05")

    async def stall(agent, prompt):
        await asyncio.sleep(1)

    monkeypatch.setattr(pipeline, "_invoke_repair_agent", stall)
    with pytest.raises(asyncio.TimeoutError):
        await pipeline._repair("not-json", StructuredResponseError("malformed_json", "bad"))


@pytest.mark.asyncio
async def test_graph_model_timeout_is_bounded(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("RESEARCH_GRAPH_TIMEOUT_SECONDS", "0.05")

    class StalledGraph:
        async def stream_async(self, *args, **kwargs):
            await asyncio.sleep(1)
            yield {"type": "multiagent_result", "result": None}

    monkeypatch.setattr(pipeline, "build_strands_graph", lambda: StalledGraph())
    with pytest.raises(asyncio.TimeoutError):
        await pipeline._invoke_graph("test", REQUEST)


@pytest.mark.asyncio
async def test_invoke_graph_reports_each_node_completion_as_it_happens(monkeypatch):
    pipeline = ResearchPipeline()

    class FakeStreamingGraph:
        async def stream_async(self, *args, **kwargs):
            for node_id in ["campaign_analyst", "evidence_researcher", "people_researcher", "synthesizer"]:
                yield {"type": "multiagent_node_start", "node_id": node_id}
                yield {"type": "multiagent_node_stop", "node_id": node_id}
            yield {"type": "multiagent_result", "result": "the-graph-result"}

    monkeypatch.setattr(pipeline, "build_strands_graph", lambda: FakeStreamingGraph())
    progress_calls = []

    async def on_progress(node, status, message):
        progress_calls.append((node, status, message))

    result = await pipeline._invoke_graph("test", REQUEST, on_progress)

    assert result == "the-graph-result"
    assert [call[0] for call in progress_calls] == ["campaign_analyst", "evidence_researcher", "people_researcher", "synthesizer"]
    assert all(call[1] == "completed" for call in progress_calls)
    assert dict((call[0], call[2]) for call in progress_calls)["evidence_researcher"] == "Verifying funders and grants."


@pytest.mark.asyncio
async def test_discovery_timeout_is_bounded(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("RESEARCH_DISCOVERY_TIMEOUT_SECONDS", "0.05")

    async def stall(request):
        await asyncio.sleep(1)

    monkeypatch.setattr(pipeline, "_collect_research_context", stall)
    with pytest.raises(asyncio.TimeoutError):
        await pipeline._bounded_research_context(REQUEST)


@pytest.mark.asyncio
async def test_candidate_followup_timeout_is_bounded(monkeypatch):
    pipeline = ResearchPipeline()
    monkeypatch.setenv("RESEARCH_FOLLOWUP_DISCOVERY_TIMEOUT_SECONDS", "0.05")

    async def stall(request, names, start_index):
        await asyncio.sleep(1)

    monkeypatch.setattr(pipeline, "_candidate_followup_context", stall)
    with pytest.raises(asyncio.TimeoutError):
        await pipeline._bounded_candidate_followup(REQUEST, ["Example Foundation"], 10)


@pytest.mark.asyncio
async def test_slow_public_provider_is_bounded_independently():
    pipeline = ResearchPipeline()

    def stalled_provider():
        import time
        time.sleep(1)

    with pytest.raises(asyncio.TimeoutError):
        await pipeline._call_public_tool(stalled_provider, timeout=.05)


@pytest.mark.asyncio
async def test_discovery_fetches_search_leads_before_exposing_them_as_evidence(monkeypatch):
    import app.graph.pipeline as pipeline_module

    pipeline = ResearchPipeline()
    monkeypatch.setattr(pipeline_module, "crawl_public_site", lambda *args: {"pages": [{"url": "https://water.example.org/about", "source_type": "public_site_crawl", "text": "Nonprofit page"}]})
    monkeypatch.setattr(pipeline_module, "search_public_web", lambda query: {"provider": "test", "results": [{"url": "https://funder.example.org/grants"}, {"url": "https://blocked.example.org"}]})
    monkeypatch.setattr(pipeline_module, "search_nonprofit_explorer", lambda query: {"organizations": []})
    monkeypatch.setattr(pipeline_module, "search_irs_filings", lambda query: {"provider": "irs_propublica", "pages": [{"url": "https://example.org/990", "title": "IRS filing", "source_type": "irs_form_990", "text": "Recent filing data"}]})
    monkeypatch.setattr(pipeline_module, "search_grants_gov", lambda query: {"provider": "grants_gov", "pages": []})
    monkeypatch.setattr(pipeline_module, "search_usaspending", lambda query: {"provider": "usaspending", "pages": []})
    monkeypatch.setattr(pipeline_module, "search_candid_grants", lambda query: {"provider": "candid", "pages": []})

    def fetch(url):
        if "blocked" in url:
            raise RuntimeError("blocked")
        return {"url": url, "title": "Grant page", "source_type": "public_web", "text": "Funds drinking water wells in Malawi"}

    monkeypatch.setattr(pipeline_module, "fetch_public_page", fetch)
    context = await pipeline._collect_research_context(REQUEST)
    assert [page["url"] for page in context["fetched_discovery_pages"]] == ["https://funder.example.org/grants"]
    assert context["provider_evidence_pages"][0]["source_id"] == "src_003"
    assert context["evidence_providers"] == ["irs_propublica", "grants_gov", "usaspending", "candid"]
    assert context["discovery_provider"] == "test"


@pytest.mark.asyncio
async def test_discovery_fetch_budget_is_distributed_across_search_intents(monkeypatch):
    import app.graph.pipeline as pipeline_module

    pipeline = ResearchPipeline()
    monkeypatch.setattr(pipeline_module, "crawl_public_site", lambda *args: {"pages": []})
    search_number = 0

    def search(query):
        nonlocal search_number
        search_number += 1
        number = search_number
        return {"provider": "test", "results": [
            {"url": f"https://source.example/q{number}-result{result}"}
            for result in range(1, 7)
        ]}

    monkeypatch.setattr(pipeline_module, "search_public_web", search)
    monkeypatch.setattr(pipeline_module, "search_nonprofit_explorer", lambda query: {"organizations": []})
    monkeypatch.setattr(pipeline_module, "search_irs_filings", lambda query: {"provider": "irs", "pages": []})
    monkeypatch.setattr(pipeline_module, "search_grants_gov", lambda query: {"provider": "grants", "pages": []})
    monkeypatch.setattr(pipeline_module, "search_usaspending", lambda query: {"provider": "spending", "pages": []})
    monkeypatch.setattr(pipeline_module, "search_candid_grants", lambda query: {"provider": "candid", "pages": []})
    monkeypatch.setattr(pipeline_module, "fetch_public_page", lambda url: {"url": url, "title": url, "source_type": "public_web", "text": "Evidence page"})

    context = await pipeline._collect_research_context(REQUEST)
    urls = [page["url"] for page in context["fetched_discovery_pages"]]
    assert urls[:6] == [f"https://source.example/q{query}-result1" for query in range(1, 7)]
    assert urls[6:] == [f"https://source.example/q{query}-result2" for query in range(1, 3)]


def test_candidate_source_must_match_and_is_anchored_to_fetched_page():
    envelope = parse_candidate_envelope(json.dumps(valid_payload()))
    assert len(ResearchPipeline._verified_candidates(valid_context(), envelope)) == 1
    wrong_context = valid_context()
    wrong_context["fetched_discovery_pages"][0]["text"] = "A generic foundation homepage"
    assert ResearchPipeline._verified_candidates(wrong_context, envelope) == []


def test_no_grant_amount_means_no_recommended_ask():
    payload = valid_payload()
    payload["prospects"][0]["grants"] = []
    candidate = parse_candidate_envelope(json.dumps(payload)).prospects[0]
    promoted = ResearchPipeline._promote(REQUEST, candidate)
    assert promoted.recommended_ask_min is None
    assert promoted.recommended_ask_max is None
    assert "no reliable grant amount" in promoted.ask_rationale.lower()


def test_unknown_year_grant_is_pruned_before_strict_promotion():
    payload = valid_payload()
    payload["prospects"][0]["grants"][0]["year"] = 0
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(valid_context(), envelope)
    assert verified[0].grants == []
    promoted = ResearchPipeline._promote(REQUEST, verified[0])
    assert promoted.grants == []
    assert promoted.recommended_ask_min is None


def test_citation_punctuation_drift_is_anchored_to_exact_page_passage():
    payload = valid_payload(excerpt="Funds safe-water wells, in Malawi")
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(valid_context(), envelope)
    assert len(verified) == 1
    assert verified[0].sources[0].excerpt_or_locator == "Funds safe water wells in Malawi"


def test_weakly_related_paraphrase_is_not_accepted_as_evidence():
    payload = valid_payload(excerpt="Supports international community development projects worldwide")
    envelope = parse_candidate_envelope(json.dumps(payload))
    assert ResearchPipeline._verified_candidates(valid_context(), envelope) == []


def test_defensible_paraphrase_is_anchored_to_exact_source_text():
    context = valid_context()
    context["fetched_discovery_pages"][0]["text"] = (
        "The foundation funds safe drinking water wells for rural communities in Malawi."
    )
    payload = valid_payload(excerpt="Funding drinking water wells in rural Malawi communities")
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(context, envelope)
    assert len(verified) == 1
    assert verified[0].sources[0].excerpt_or_locator == context["fetched_discovery_pages"][0]["text"]


def test_unknown_source_id_is_not_rescued_by_model_supplied_url():
    payload = valid_payload()
    payload["prospects"][0]["sources"][0]["id"] = "invented"
    payload["prospects"][0]["claims"][0]["source_ids"] = ["invented"]
    payload["prospects"][0]["grants"][0]["source_id"] = "invented"
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified, diagnostics = ResearchPipeline._verify_candidates(valid_context(), envelope)
    assert verified == []
    assert diagnostics["unknown_source_id_count"] == 1


def test_flattened_html_passage_can_ground_a_multi_fragment_citation():
    filler = "Background information about the organization. " * 20
    context = valid_context()
    context["fetched_discovery_pages"][0]["text"] = (
        filler + "Francis Goelet Charitable Lead Trusts. Clubhouse International donor. " + filler
    )
    payload = valid_payload(
        name="Francis Goelet Charitable Lead Trusts",
        excerpt="Francis Goelet Charitable Lead Trusts supported Clubhouse International as a donor",
    )
    payload["prospects"][0]["claims"][0]["claim"] = "The trust supported Clubhouse International."
    payload["prospects"][0]["grants"] = []
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(context, envelope)
    assert len(verified) == 1
    assert "Francis Goelet Charitable Lead Trusts" in verified[0].sources[0].excerpt_or_locator
    assert "supported" not in verified[0].sources[0].excerpt_or_locator


def test_source_id_controls_canonical_metadata_even_when_model_retypes_it_wrong():
    payload = valid_payload()
    payload["prospects"][0]["sources"][0]["url"] = "https://hallucinated.example/bad"
    payload["prospects"][0]["sources"][0]["title"] = "Model supplied title"
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(valid_context(), envelope)
    assert str(verified[0].sources[0].url) == "https://example.org/grants"
    assert verified[0].sources[0].title == "Canonical grants page"


def test_invalid_source_and_dependent_claim_are_pruned_without_losing_valid_claim():
    payload = valid_payload()
    prospect = payload["prospects"][0]
    prospect["sources"].append({"id": "bad", "title": "About", "url": "https://example.org/grants", "source_type": "official", "excerpt_or_locator": "About Us"})
    prospect["claims"].append({"key": "unsupported", "claim": "An unsupported factual claim.", "confidence": "low", "source_ids": ["bad"]})
    envelope = parse_candidate_envelope(json.dumps(payload))
    verified = ResearchPipeline._verified_candidates(valid_context(), envelope)
    assert [claim.key for claim in verified[0].claims] == ["cause"]
    assert [source.id for source in verified[0].sources] == ["s1"]
