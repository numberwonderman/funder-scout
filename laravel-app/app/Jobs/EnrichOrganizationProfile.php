<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\AgentServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnrichOrganizationProfile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public int $organizationId) {}

    public function handle(AgentServiceClient $agent): void
    {
        $organization = Organization::findOrFail($this->organizationId);
        $organization->update(['enrichment_status' => 'running', 'enrichment_started_at' => now(), 'enrichment_message' => 'Reading your public website…']);

        $response = $agent->enrichOrganization($organization);
        $sources = collect($response['sources'] ?? [])->keyBy('id');
        $suggestions = [];
        $updates = [];
        $listFields = ['program_areas', 'populations_served', 'needs', 'keywords'];
        $integerFields = ['organization_age', 'annual_budget', 'staff_size'];

        foreach ($response['facts'] ?? [] as $fact) {
            $field = $fact['field'] ?? null;
            $value = $fact['value'] ?? null;
            if (! in_array($field, ['name', 'mission', 'geography', 'ein', 'nonprofit_status', ...$listFields, ...$integerFields], true)) {
                continue;
            }
            if (in_array($field, $listFields, true) && ! is_array($value)) {
                continue;
            }
            if (in_array($field, $integerFields, true) && ! is_int($value)) {
                continue;
            }
            if ($field === 'nonprofit_status' && ! in_array($value, ['501c3', 'other'], true)) {
                continue;
            }
            $evidence = collect($fact['source_ids'] ?? [])->map(fn (string $id): ?array => $sources->get($id))->filter()->values()->all();
            if ($evidence === []) {
                continue;
            }
            $current = $organization->getAttribute($field);
            $empty = $current === null || $current === '' || $current === [] || ($field === 'nonprofit_status' && $current === 'unknown');
            if ($empty) {
                $updates[$field] = $value;
            }
            $suggestions[] = ['field' => $field, 'value' => $value, 'confidence' => $fact['confidence'] ?? 'medium', 'status' => $empty ? 'applied' : 'review', 'sources' => $evidence];
        }

        $organization->update([...$updates, 'enrichment_status' => 'completed', 'enrichment_message' => count($updates).' blank fields filled from verified website evidence.', 'enriched_at' => now(), 'enrichment_suggestions' => $suggestions, 'enrichment_sources' => array_values($sources->all())]);
    }

    public function failed(Throwable $exception): void
    {
        Organization::whereKey($this->organizationId)->update(['enrichment_status' => 'failed', 'enrichment_message' => 'Website research could not be completed. Your existing profile was not changed.']);
    }
}
