<?php

namespace Tests\Feature;

use App\Jobs\EnrichOrganizationProfile;
use App\Models\Organization;
use App\Services\AgentServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class OrganizationEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_change_queues_background_enrichment(): void
    {
        Queue::fake();

        $response = $this->postJson(route('organization.enrich'), ['website' => 'https://example.org']);

        $response->assertAccepted()->assertJsonPath('status', 'queued');
        $organization = Organization::firstOrFail();
        $this->assertSame('https://example.org', $organization->website);
        Queue::assertPushed(EnrichOrganizationProfile::class, fn ($job): bool => $job->organizationId === $organization->id);
    }

    public function test_agent_fills_blanks_and_preserves_member_information(): void
    {
        $organization = Organization::create(['name' => 'Member Name', 'website' => 'https://example.org', 'mission' => 'A mission written by a member.', 'nonprofit_status' => 'unknown']);
        $source = ['id' => 'org_01', 'title' => 'About', 'url' => 'https://example.org/about', 'source_type' => 'official_site', 'excerpt_or_locator' => 'We provide community mental health services throughout Vermont.'];
        $this->mock(AgentServiceClient::class, function (MockInterface $mock) use ($source): void {
            $mock->shouldReceive('enrichOrganization')->once()->andReturn([
                'sources' => [$source],
                'facts' => [
                    ['field' => 'name', 'value' => 'Agent Name', 'confidence' => 'high', 'source_ids' => ['org_01']],
                    ['field' => 'mission', 'value' => 'Agent mission', 'confidence' => 'high', 'source_ids' => ['org_01']],
                    ['field' => 'geography', 'value' => 'Vermont', 'confidence' => 'high', 'source_ids' => ['org_01']],
                    ['field' => 'program_areas', 'value' => ['Mental health'], 'confidence' => 'high', 'source_ids' => ['org_01']],
                ],
            ]);
        });

        (new EnrichOrganizationProfile($organization->id))->handle(app(AgentServiceClient::class));
        $organization->refresh();

        $this->assertSame('Member Name', $organization->name);
        $this->assertSame('A mission written by a member.', $organization->mission);
        $this->assertSame('Vermont', $organization->geography);
        $this->assertSame(['Mental health'], $organization->program_areas);
        $this->assertSame('completed', $organization->enrichment_status);
        $this->assertSame('review', collect($organization->enrichment_suggestions)->firstWhere('field', 'mission')['status']);
    }
}
