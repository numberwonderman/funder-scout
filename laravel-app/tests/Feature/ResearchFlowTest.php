<?php

namespace Tests\Feature;

use App\Jobs\ProcessResearchRun;
use App\Models\Organization;
use App\Models\ResearchRun;
use App\Services\AgentServiceClient;
use App\Services\ResearchResultIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class ResearchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_pages_are_available_and_organization_profile_is_reused(): void
    {
        $this->get(route('campaigns.index'))->assertOk()->assertSee('Your fundraising work');
        $this->get(route('prospects.index'))->assertOk()->assertSee('Funding opportunities');
        $this->get(route('settings.index'))->assertOk()->assertSee('Research connections');

        $this->put(route('organization.update'), ['name' => 'Vermont Neighbors', 'website' => 'https://vermont.example.org', 'mission' => 'We expand reliable food access across rural Vermont.', 'geography' => 'Vermont', 'nonprofit_status' => '501c3', 'fiscal_sponsorship_status' => 'not_sponsored', 'program_areas' => "Food security\nMobile pantry", 'populations_served' => 'Rural families', 'organization_age' => 12, 'annual_budget' => 650000, 'staff_size' => 8, 'desired_funding_categories' => 'Program support, General operating', 'desired_grant_min' => 2500, 'desired_grant_max' => 20000, 'needs' => 'Refrigerated vehicle, Fuel', 'keywords' => 'rural food access', 'exclusions' => 'Research only', 'prefers_unrestricted' => 1])->assertRedirect();

        $organization = Organization::firstOrFail();
        $this->get(route('organization.edit'))->assertOk()->assertSee('Vermont Neighbors')->assertSee('Mobile pantry');
        $this->get(route('research.index'))->assertOk()->assertSee('Researching for')->assertSee('Vermont Neighbors');
        $this->post(route('research.store'), ['organization_id' => $organization->id, 'organization_name' => $organization->name, 'website' => $organization->website, 'mission' => $organization->mission, 'nonprofit_status' => $organization->nonprofit_status, 'fiscal_sponsorship_status' => $organization->fiscal_sponsorship_status, 'program_areas' => implode(', ', $organization->program_areas), 'populations_served' => implode(', ', $organization->populations_served), 'annual_budget' => $organization->annual_budget, 'staff_size' => $organization->staff_size, 'desired_grant_min' => $organization->desired_grant_min, 'desired_grant_max' => $organization->desired_grant_max, 'keywords' => implode(', ', $organization->keywords), 'exclusions' => implode(', ', $organization->exclusions), 'prefers_unrestricted' => 1, 'campaign_title' => 'Expand mobile pantry delivery', 'description' => 'Fund refrigerated delivery and food purchases for rural families.', 'goal_amount' => 20000, 'geography' => 'Vermont'])->assertRedirect();

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('campaigns', ['organization_id' => $organization->id, 'title' => 'Expand mobile pantry delivery']);
        $this->assertSame(['Program support', 'General operating'], $organization->refresh()->desired_funding_categories);
    }

    public function test_research_progress_page_polls_without_reload_loop(): void
    {
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();

        $response = $this->get(route('research.show', $run));

        $response->assertOk();
        $response->assertDontSee('location.reload()', false);
        $response->assertSee('window.location.replace', false);
        $response->assertSee('requestInFlight', false);
    }

    public function test_campaign_runs_and_evidence_backed_results_are_ingested(): void
    {
        Http::fake(function ($request) {
            $this->assertSame([], $request->data()['nonprofit']['desired_funding_categories']);
            $this->assertSame([], $request->data()['nonprofit']['needs']);

            return Http::response($this->fixture($request->data()['research_run_id']));
        });
        $response = $this->post('/research', ['organization_name' => 'FutureForge Youth', 'website' => 'https://futureforge.example.org', 'campaign_title' => 'New labs', 'description' => 'Expand robotics labs into three middle schools.', 'goal_amount' => 75000, 'geography' => 'Philadelphia', 'board_members' => 'Sarah Johnson']);
        $run = ResearchRun::firstOrFail();
        $response->assertRedirect(route('research.show', $run));
        $this->post(route('research.execute', $run))->assertOk();
        $this->assertSame('completed', $run->refresh()->status->value);
        $this->assertDatabaseHas('prospects', ['name' => 'Miller Family Foundation', 'fit_score' => 43]);
        $this->assertDatabaseCount('evidence_sources', 1);
        $this->assertDatabaseCount('knowledge_entities', 2);
        $this->assertDatabaseCount('knowledge_edges', 1);
        $this->assertDatabaseCount('path_to_money_steps', 9);
        $this->assertDatabaseHas('research_tasks', ['question' => 'Organization legally eligible for FutureForge Youth?']);
    }

    public function test_member_profile_feedback_and_application_workspace_persist(): void
    {
        Http::fake(fn ($request) => Http::response($this->fixture($request->data()['research_run_id'])));
        $this->post('/research', ['organization_name' => 'Peer Support Vermont', 'website' => 'https://peer.example.org', 'mission' => 'Peer-run mental health recovery.', 'nonprofit_status' => '501c3', 'fiscal_sponsorship_status' => 'not_sponsored', 'program_areas' => 'Mental health, Workforce development', 'populations_served' => 'Adults with serious mental illness', 'annual_budget' => 180000, 'staff_size' => 3, 'desired_grant_min' => 500, 'desired_grant_max' => 25000, 'keywords' => 'peer support, clubhouse', 'exclusions' => 'research only', 'prefers_unrestricted' => 1, 'campaign_title' => 'Launch a peer clubhouse', 'description' => 'Launch vocational and peer support programming for adults.', 'goal_amount' => 10000, 'geography' => 'Vermont']);
        $run = ResearchRun::firstOrFail();
        $this->post(route('research.execute', $run))->assertOk();
        $prospect = $run->prospects()->firstOrFail();

        $this->assertDatabaseHas('organizations', ['name' => 'Peer Support Vermont', 'annual_budget' => 180000, 'staff_size' => 3]);
        $this->post(route('prospects.feedback', $prospect), ['status' => 'interested'])->assertRedirect();
        $this->assertDatabaseHas('prospect_feedback', ['prospect_id' => $prospect->id, 'status' => 'interested']);
        $this->get(route('prospects.workspace', $prospect))->assertOk()->assertSee('Peer-run mental health recovery.');
        $this->assertDatabaseHas('application_workspaces', ['prospect_id' => $prospect->id]);
    }

    public function test_invalid_payload_is_rejected_before_any_existing_results_are_changed(): void
    {
        Http::fake(fn ($request) => Http::response($this->fixture($request->data()['research_run_id'])));
        $this->post('/research', ['organization_name' => 'FutureForge Youth', 'website' => 'https://futureforge.example.org', 'campaign_title' => 'New labs', 'description' => 'Expand robotics labs into three middle schools.', 'goal_amount' => 75000]);
        $run = ResearchRun::firstOrFail();
        $this->post(route('research.execute', $run))->assertOk();
        $payload = $this->fixture($run->uuid);
        $payload['prospects'][] = ['name' => 'Invalid second prospect', 'claims' => [['key' => 'cause', 'claim' => 'Unsupported.', 'sources' => []]]];

        try {
            app(ResearchResultIngestor::class)->ingest($run, $payload);
            $this->fail('Expected invalid evidence metadata to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('Every prospect requires a name and evidence-backed claims.', $exception->getMessage());
        }

        $this->assertDatabaseCount('prospects', 1);
        $this->assertDatabaseHas('prospects', ['name' => 'Miller Family Foundation']);
        $this->assertDatabaseCount('evidence_sources', 1);
    }

    public function test_zero_prospect_landscape_is_completed_and_teaches_from_exclusions(): void
    {
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();
        $payload = $this->fixture($run->uuid);
        $payload['prospects'] = [];
        $payload['campaign_brief'] = ['causes' => ['Water and sanitation'], 'intervention' => 'Drill wells', 'geography' => 'Malawi', 'goal_amount' => 10000, 'likely_funder_types' => ['Water foundations'], 'important_exclusions' => ['Wrong geography']];
        $payload['excluded_candidates'] = [['name' => 'Generic Health Agency', 'reason' => 'Wrong program area.', 'source_count' => 1]];
        $payload['research_notes'] = ['No candidate passed the relevance gate.'];

        app(ResearchResultIngestor::class)->ingest($run, $payload);

        $this->assertSame('completed', $run->refresh()->status->value);
        $this->assertDatabaseCount('prospects', 0);
        $this->get(route('research.show', $run))->assertOk()->assertSee('What we ruled out')->assertSee('Generic Health Agency');
    }

    public function test_stale_workflow_and_unsubstantiated_score_signals_are_rejected(): void
    {
        $this->post('/research', ['organization_name' => 'FutureForge Youth', 'website' => 'https://futureforge.example.org', 'campaign_title' => 'New labs', 'description' => 'Expand robotics labs.', 'goal_amount' => 75000]);
        $run = ResearchRun::firstOrFail();
        $payload = $this->fixture($run->uuid);
        unset($payload['workflow_version']);

        $this->expectException(UnexpectedValueException::class);
        app(ResearchResultIngestor::class)->ingest($run, $payload);
    }

    public function test_grant_and_relationship_scores_require_verified_records(): void
    {
        $this->post('/research', ['organization_name' => 'FutureForge Youth', 'website' => 'https://futureforge.example.org', 'campaign_title' => 'New labs', 'description' => 'Expand robotics labs.', 'goal_amount' => 75000]);
        $run = ResearchRun::firstOrFail();
        $payload = $this->fixture($run->uuid);
        $payload['prospects'][0]['score_signals']['historical_giving'] = .8;

        $this->expectException(UnexpectedValueException::class);
        app(ResearchResultIngestor::class)->ingest($run, $payload);
    }

    public function test_agent_timeout_failure_records_failed_run_without_prospects(): void
    {
        Http::fake(fn () => Http::response(['detail' => 'Live Strands research failed closed (TimeoutError)'], 503));
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();

        $this->post(route('research.execute', $run))->assertStatus(503);

        $this->assertSame('failed', $run->refresh()->status->value);
        $this->assertNotNull($run->failure_message);
        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_streamed_research_persists_each_node_progress_as_it_arrives(): void
    {
        config(['services.agent.demo_mode' => false]);
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();

        $ndjson = implode("\n", [
            json_encode(['type' => 'progress', 'node' => 'campaign_analyst', 'status' => 'completed', 'message' => 'Understanding campaign fit and exclusions.']),
            json_encode(['type' => 'progress', 'node' => 'evidence_researcher', 'status' => 'completed', 'message' => 'Verifying funders and grants.']),
            json_encode(['type' => 'result', 'response' => ['research_run_id' => $run->uuid, 'mode' => 'live']]),
        ])."\n";

        Http::fake([
            rtrim((string) config('services.agent.url'), '/').'/*' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        $seen = [];
        $result = (new AgentServiceClient)->research($run, function (string $node, string $status, string $message) use (&$seen): void {
            $seen[] = [$node, $status, $message];
        });

        $this->assertSame(['research_run_id' => $run->uuid, 'mode' => 'live'], $result);
        $this->assertSame([
            ['campaign_analyst', 'completed', 'Understanding campaign fit and exclusions.'],
            ['evidence_researcher', 'completed', 'Verifying funders and grants.'],
        ], $seen);
    }

    public function test_streamed_research_error_line_fails_closed_with_matching_diagnosis(): void
    {
        config(['services.agent.demo_mode' => false]);
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();

        $ndjson = json_encode(['type' => 'error', 'detail' => 'Live Strands research failed closed [timeout]'])."\n";

        Http::fake([
            rtrim((string) config('services.agent.url'), '/').'/*' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
        ]);

        try {
            (new AgentServiceClient)->research($run);
            $this->fail('Expected the streamed error line to raise.');
        } catch (\RuntimeException $exception) {
            (new ProcessResearchRun($run->id))->failed($exception);
        }

        $this->assertSame('The research service exceeded its configured deadline. No results were saved.', $run->refresh()->failure_message);
    }

    public function test_live_research_uses_local_strands_pipeline_when_fastapi_is_unreachable(): void
    {
        config(['services.agent.demo_mode' => false]);
        Http::preventStrayRequests();
        Http::fake([rtrim((string) config('services.agent.url'), '/').'/*' => Http::failedConnection()]);
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();
        $client = new class extends AgentServiceClient
        {
            protected function runLocalAgent(array $payload, bool $demoMode): array
            {
                return ['research_run_id' => $payload['research_run_id'], 'mode' => $demoMode ? 'demo' : 'live'];
            }
        };

        $result = $client->research($run);

        $this->assertSame(['research_run_id' => $run->uuid, 'mode' => 'live'], $result);
        Http::assertSentCount(3);
    }

    public function test_failed_local_fallback_preserves_service_unreachable_diagnosis(): void
    {
        Http::fake(fn () => throw new ConnectionException('unreachable'));
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water access for rural communities.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();
        $client = new class extends AgentServiceClient
        {
            protected function runLocalAgent(array $payload, bool $demoMode): array
            {
                throw new \RuntimeException('fallback failed');
            }
        };

        try {
            $client->research($run);
            $this->fail('Expected connection failure.');
        } catch (ConnectionException) {
            (new ProcessResearchRun($run->id))->failed(new ConnectionException('unreachable'));
        }

        $this->assertSame('The research service could not be reached. No results were saved.', $run->refresh()->failure_message);
    }

    public function test_analyzing_run_is_not_executed_twice(): void
    {
        Http::fake();
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();
        $run->update(['status' => 'analyzing_nonprofit']);

        $this->post(route('research.execute', $run))->assertStatus(202)->assertJson(['status' => 'running']);

        Http::assertNothingSent();
    }

    public function test_stale_analyzing_run_fails_cleanly(): void
    {
        Http::fake();
        $this->post('/research', ['organization_name' => 'Water Partners', 'website' => 'https://water.example.org', 'campaign_title' => 'Drill wells', 'description' => 'Safe water in Malawi.', 'goal_amount' => 10000]);
        $run = ResearchRun::firstOrFail();
        $run->forceFill(['status' => 'analyzing_nonprofit', 'started_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(2)])->saveQuietly();

        $this->post(route('research.execute', $run))->assertStatus(503)->assertJson(['status' => 'failed']);

        $this->assertSame('failed', $run->refresh()->status->value);
        $this->assertDatabaseCount('prospects', 0);
        Http::assertNothingSent();
    }

    private function fixture(string $uuid): array
    {
        $source = ['title' => 'Fictional demo source', 'url' => 'https://demo.example.org/source', 'source_type' => 'demo_fixture', 'retrieved_at' => now()->toIso8601String(), 'excerpt_or_locator' => 'Row 1'];

        return ['workflow_version' => 'strands-v3', 'research_run_id' => $uuid, 'mode' => 'demo', 'graph' => ['campaign_analyst', 'evidence_researcher', 'people_researcher', 'synthesizer'], 'events' => [['node' => 'campaign_analyst', 'status' => 'completed', 'message' => 'Analyzed.', 'metadata' => []]], 'prospects' => [['name' => 'Miller Family Foundation', 'funder_type' => 'Family foundation', 'ein' => null, 'summary' => 'A fit.', 'confidence' => 'high', 'claims' => [['key' => 'giving', 'claim' => 'One relevant grant.', 'value' => 1, 'confidence' => 'high', 'sources' => [$source]]], 'grants' => [], 'people' => [], 'relationships' => [], 'score_signals' => ['cause_alignment' => .93, 'historical_giving' => 0, 'geographic_fit' => 1, 'grant_size_fit' => 0, 'recency' => 0, 'relationship_strength' => 0], 'recommended_ask_min' => null, 'recommended_ask_max' => null, 'ask_rationale' => 'Insufficient verified grant data.']]];
    }
}
