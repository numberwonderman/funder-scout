<?php

namespace Tests\Unit;

use App\Services\CrmEnrichmentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmEnrichmentServiceTest extends TestCase
{
    public function test_hubspot_contact_creates_evidence_backed_person_and_warm_path(): void
    {
        config(['services.hubspot.access_token' => 'test-token', 'services.hubspot.portal_id' => '123']);
        Http::preventStrayRequests();
        Http::fake(['api.hubapi.com/*' => Http::response(['results' => [[
            'id' => '456',
            'properties' => ['firstname' => 'Amina', 'lastname' => 'Jones', 'company' => 'MacArthur Foundation', 'jobtitle' => 'Program Officer'],
        ]]])]);
        $payload = $this->payload();

        $result = app(CrmEnrichmentService::class)->enrich($payload);

        $this->assertSame('Amina Jones', $result['prospects'][0]['people'][0]['name']);
        $this->assertSame('crm_hubspot', $result['prospects'][0]['relationships'][0]['sources'][0]['source_type']);
        $this->assertSame(.5, $result['prospects'][0]['score_signals']['relationship_strength']);
        Http::assertSentCount(1);
    }

    public function test_public_people_remain_as_fallback_without_crm_configuration(): void
    {
        config(['services.hubspot.access_token' => null, 'services.hubspot.portal_id' => null]);
        Http::preventStrayRequests();
        $payload = $this->payload();
        $payload['prospects'][0]['people'] = [['name' => 'Public Officer', 'role' => 'Director', 'confidence' => 'high', 'sources' => [['title' => 'Leadership', 'url' => 'https://example.org/team', 'source_type' => 'official', 'retrieved_at' => now()->toIso8601String(), 'excerpt_or_locator' => 'Public Officer, Director of grantmaking programs']]]];

        $result = app(CrmEnrichmentService::class)->enrich($payload);

        $this->assertSame('Public Officer', $result['prospects'][0]['people'][0]['name']);
        $this->assertSame([], $result['prospects'][0]['relationships']);
        Http::assertNothingSent();
    }

    public function test_public_people_remain_when_hubspot_is_unavailable(): void
    {
        config(['services.hubspot.access_token' => 'test-token', 'services.hubspot.portal_id' => '123']);
        Http::preventStrayRequests();
        Http::fake(['api.hubapi.com/*' => Http::failedConnection()]);
        $payload = $this->payload();
        $payload['prospects'][0]['people'] = [[
            'name' => 'Public Officer',
            'role' => 'Director',
            'confidence' => 'high',
            'sources' => [[
                'title' => 'Leadership',
                'url' => 'https://example.org/team',
                'source_type' => 'official',
                'retrieved_at' => now()->toIso8601String(),
                'excerpt_or_locator' => 'Public Officer, Director of grantmaking programs',
            ]],
        ]];

        $result = app(CrmEnrichmentService::class)->enrich($payload);

        $this->assertSame('Public Officer', $result['prospects'][0]['people'][0]['name']);
        $this->assertSame([], $result['prospects'][0]['relationships']);
        Http::assertSentCount(1);
    }

    private function payload(): array
    {
        return ['prospects' => [[
            'name' => 'The MacArthur Foundation',
            'people' => [],
            'relationships' => [],
            'score_signals' => ['relationship_strength' => 0],
        ]]];
    }
}
