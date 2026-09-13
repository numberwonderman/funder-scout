<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CrmEnrichmentService
{
    public function enrich(array $payload): array
    {
        $token = (string) config('services.hubspot.access_token');
        $portalId = (string) config('services.hubspot.portal_id');
        if ($token === '' || $portalId === '') {
            return $payload;
        }

        foreach ($payload['prospects'] ?? [] as $index => $prospect) {
            $matches = $this->findContacts($token, (string) ($prospect['name'] ?? ''));
            $relationships = $prospect['relationships'] ?? [];
            $people = $prospect['people'] ?? [];

            foreach ($matches as $contact) {
                $properties = $contact['properties'] ?? [];
                $name = trim(($properties['firstname'] ?? '').' '.($properties['lastname'] ?? ''));
                $company = trim((string) ($properties['company'] ?? ''));
                if ($name === '' || ! $this->sameOrganization($company, (string) $prospect['name'])) {
                    continue;
                }
                $role = trim((string) ($properties['jobtitle'] ?? 'CRM contact')) ?: 'CRM contact';
                $source = [
                    'title' => 'HubSpot CRM contact record',
                    'url' => "https://app.hubspot.com/contacts/{$portalId}/record/0-1/{$contact['id']}",
                    'source_type' => 'crm_hubspot',
                    'retrieved_at' => now()->toIso8601String(),
                    'excerpt_or_locator' => "Contact {$contact['id']}: {$name}; {$role}; {$company}",
                ];
                if (! collect($people)->contains(fn ($person) => Str::lower($person['name'] ?? '') === Str::lower($name))) {
                    $people[] = ['name' => $name, 'role' => $role, 'confidence' => 'high', 'sources' => [$source]];
                }
                $relationships[] = [
                    'from_name' => $name,
                    'relationship' => "CRM contact at {$company}",
                    'to_name' => (string) $prospect['name'],
                    'sources' => [$source],
                ];
            }

            $payload['prospects'][$index]['people'] = array_values($people);
            $payload['prospects'][$index]['relationships'] = array_values($relationships);
            $payload['prospects'][$index]['score_signals']['relationship_strength'] = min(1, count($relationships) / 2);
        }

        return $payload;
    }

    private function findContacts(string $token, string $organization): array
    {
        if (trim($organization) === '') {
            return [];
        }
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(8)
                ->connectTimeout(3)
                ->post('https://api.hubapi.com/crm/objects/2026-03/contacts/search', [
                    'query' => $organization,
                    'limit' => 10,
                    'properties' => ['firstname', 'lastname', 'company', 'jobtitle'],
                ]);
        } catch (Throwable) {
            return [];
        }

        return $response->successful() ? array_slice($response->json('results', []), 0, 10) : [];
    }

    private function sameOrganization(string $left, string $right): bool
    {
        $normalize = fn (string $value) => collect(preg_split('/[^a-z0-9]+/', Str::lower($value)))
            ->reject(fn ($word) => $word === '' || in_array($word, ['the', 'foundation', 'fund', 'inc', 'trust'], true))
            ->values();
        $a = $normalize($left);
        $b = $normalize($right);

        return $a->isNotEmpty() && $b->isNotEmpty() && $a->intersect($b)->count() >= min(2, $a->count(), $b->count());
    }
}
