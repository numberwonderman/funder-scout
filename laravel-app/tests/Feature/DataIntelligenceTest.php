<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Prospect;
use App\Services\PathToMoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_disqualification_overrides_soft_fit(): void
    {
        $prospect = $this->prospect();
        $claim = $prospect->claims()->create(['key' => 'geography', 'predicate' => 'geographic_eligibility', 'claim' => 'Only Cincinnati city organizations may apply; the member operates outside the city.', 'confidence' => 'high', 'status' => 'contradicted']);
        $claim->evidence()->create(['title' => 'Official guidelines', 'url' => 'https://foundation.example.org/guidelines', 'source_type' => 'official_funder_guidelines', 'retrieved_at' => now(), 'excerpt_or_locator' => 'Eligibility section', 'source_tier' => 1]);

        app(PathToMoneyService::class)->evaluate($prospect);

        $this->assertSame(0, $prospect->refresh()->fit_score);
        $this->assertSame('ineligible', $prospect->path_to_money_rating);
        $this->assertDatabaseHas('path_to_money_steps', ['prospect_id' => $prospect->id, 'step_key' => 'geographic_eligibility', 'status' => 'disqualifying']);
    }

    public function test_weak_discovery_source_cannot_verify_hard_eligibility_and_creates_task(): void
    {
        $prospect = $this->prospect();
        $claim = $prospect->claims()->create(['key' => 'eligibility', 'predicate' => 'legal_eligibility', 'claim' => 'A directory says nonprofits can apply.', 'confidence' => 'medium', 'status' => 'supported']);
        $claim->evidence()->create(['title' => 'Grant directory', 'url' => 'https://directory.example.org/listing', 'source_type' => 'grant_aggregator', 'retrieved_at' => now(), 'excerpt_or_locator' => 'Listing summary', 'source_tier' => 3]);

        app(PathToMoneyService::class)->evaluate($prospect);

        $this->assertDatabaseHas('path_to_money_steps', ['prospect_id' => $prospect->id, 'step_key' => 'legal_eligibility', 'status' => 'unknown']);
        $this->assertDatabaseHas('research_tasks', ['prospect_id' => $prospect->id, 'decision_impact' => 5, 'status' => 'queued']);
    }

    private function prospect(): Prospect
    {
        $organization = Organization::create(['name' => 'Recovery Center X', 'website' => 'https://recovery.example.org', 'geography' => 'Hamilton County, Ohio']);
        $campaign = $organization->campaigns()->create(['title' => 'Peer transportation', 'description' => 'Provide participant transportation to peer recovery programming.', 'goal_amount' => 5000]);
        $run = $campaign->researchRuns()->create(['uuid' => (string) Str::uuid(), 'status' => 'completed']);

        return $run->prospects()->create(['name' => 'ABC Community Foundation', 'funder_type' => 'Community foundation', 'summary' => 'Potential local funding source.', 'confidence' => 'high', 'fit_score' => 88, 'ask_rationale' => 'Test rationale.', 'opportunity_status' => 'worth_investigating', 'opportunity_type' => 'mission_match', 'eligibility_status' => 'not_verified']);
    }
}
