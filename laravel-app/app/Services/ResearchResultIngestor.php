<?php

namespace App\Services;

use App\Enums\ResearchStatus;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeEntity;
use App\Models\ResearchRun;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class ResearchResultIngestor
{
    public function __construct(private FitScoreService $scorer, private PathToMoneyService $pathToMoney) {}

    public function ingest(ResearchRun $run, array $payload): void
    {
        if (($payload['research_run_id'] ?? null) !== $run->uuid) {
            throw new UnexpectedValueException('Agent response correlation ID does not match.');
        }

        $scoredProspects = $this->validateAndScoreProspects($payload);

        DB::transaction(function () use ($run, $payload, $scoredProspects) {
            $run->prospects()->delete();
            $run->events()->delete();
            foreach ($payload['events'] ?? [] as $event) {
                $run->events()->create(['node' => $event['node'], 'status' => $event['status'], 'message' => $event['message'], 'metadata' => $event['metadata'] ?? []]);
            }
            foreach ($scoredProspects as $prepared) {
                $item = $prepared['prospect'];
                $score = $prepared['score'];
                $amounts = collect($item['grants'] ?? [])->pluck('amount')->filter();
                $firstSource = $item['claims'][0]['sources'][0] ?? [];
                $prospect = $run->prospects()->create([
                    'name' => $item['name'], 'funder_type' => $item['funder_type'], 'ein' => $item['ein'] ?? null,
                    'summary' => $item['summary'], 'confidence' => $item['confidence'], 'fit_score' => $score['total'],
                    'recommended_ask_min' => $item['recommended_ask_min'] ?? null, 'recommended_ask_max' => $item['recommended_ask_max'] ?? null, 'ask_rationale' => $item['ask_rationale'],
                    'opportunity_status' => $item['opportunity_status'] ?? 'worth_investigating', 'opportunity_type' => $item['opportunity_type'] ?? 'mission_match',
                    'eligibility_status' => $item['eligibility_status'] ?? 'not_verified', 'evidence_gaps' => $item['evidence_gaps'] ?? [], 'next_actions' => $item['next_actions'] ?? [],
                    'grant_title' => $firstSource['title'] ?? null, 'canonical_application_url' => $firstSource['url'] ?? null,
                    'amount_min' => $amounts->min(), 'amount_max' => $amounts->max(), 'grant_status' => 'uncertain',
                    'application_effort' => 'unknown', 'possible_disqualifiers' => $item['evidence_gaps'] ?? [], 'last_verified_at' => now(),
                ]);
                $prospect->scoreComponents()->createMany($score['components']);
                foreach ($item['claims'] as $claimData) {
                    $claim = $prospect->claims()->create(['key' => $claimData['key'], 'predicate' => $claimData['predicate'] ?? $claimData['key'], 'claim' => $claimData['claim'], 'value' => $claimData['value'] ?? null, 'confidence' => $claimData['confidence'], 'status' => $claimData['status'] ?? 'supported', 'origin' => $claimData['origin'] ?? 'publicly_verified', 'extraction_method' => $claimData['extraction_method'] ?? 'strands_structured_extraction', 'verified_at' => now(), 'expires_at' => now()->addDays($this->recheckDays($claimData['key']))]);
                    $claim->evidence()->createMany(collect($claimData['sources'])->map(fn (array $source): array => [...$source, 'source_tier' => $this->sourceTier($source), 'content_hash' => hash('sha256', ($source['url'] ?? '').'|'.($source['excerpt_or_locator'] ?? '')), 'is_archived' => str_contains(strtolower($source['source_type'] ?? ''), 'archive')])->all());
                    $this->connectKnowledgeGraph($run, $prospect, $claim);
                }
                $prospect->grants()->createMany($item['grants'] ?? []);
                $prospect->people()->createMany($item['people'] ?? []);
                $prospect->relationships()->createMany($item['relationships'] ?? []);
                $this->pathToMoney->evaluate($prospect);
            }
            $run->update(['status' => ResearchStatus::Completed, 'is_demo' => ($payload['mode'] ?? null) === 'demo', 'completed_at' => now(), 'raw_agent_payload' => $payload]);
        });
    }

    private function sourceTier(array $source): int
    {
        $type = strtolower((string) ($source['source_type'] ?? ''));
        $host = strtolower((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
        if (str_contains($type, 'demo') || str_contains($type, 'social') || str_contains($type, 'snippet')) {
            return 4;
        }
        if (str_ends_with($host, '.gov') || str_contains($type, 'official') || str_contains($type, 'government') || str_contains($type, 'irs') || str_contains($type, '990') || str_contains($type, 'award_database') || str_contains($type, 'annual_report')) {
            return 1;
        }
        if (str_contains($type, 'recipient') || str_contains($type, 'institutional') || str_contains($type, 'audited')) {
            return 2;
        }

        return 3;
    }

    private function recheckDays(string $key): int
    {
        return str_contains(strtolower($key), 'deadline') ? 7 : 90;
    }

    private function connectKnowledgeGraph(ResearchRun $run, $prospect, $claim): void
    {
        $organization = $run->campaign->organization;
        $from = KnowledgeEntity::firstOrCreate(['canonical_key' => 'organization:'.$organization->id], ['entity_type' => 'organization', 'name' => $organization->name, 'attributes' => ['website' => $organization->website]]);
        $to = KnowledgeEntity::firstOrCreate(['canonical_key' => 'funder:'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $prospect->name))], ['entity_type' => 'funder', 'name' => $prospect->name, 'attributes' => ['funder_type' => $prospect->funder_type, 'ein' => $prospect->ein]]);
        KnowledgeEdge::firstOrCreate(['from_entity_id' => $from->id, 'predicate' => $claim->predicate, 'to_entity_id' => $to->id, 'claim_id' => $claim->id], ['status' => $claim->status]);
    }

    private function validateAndScoreProspects(array $payload): array
    {
        if (($payload['workflow_version'] ?? null) !== 'strands-v3') {
            throw new UnexpectedValueException('Agent response came from an unsupported workflow version.');
        }
        if (($payload['graph'] ?? null) !== ['campaign_analyst', 'evidence_researcher', 'people_researcher', 'synthesizer']) {
            throw new UnexpectedValueException('Agent response did not come from the required Strands workflow.');
        }

        $prospects = $payload['prospects'] ?? null;
        if (! is_array($prospects) || count($prospects) > 4) {
            throw new UnexpectedValueException('Agent response must contain no more than four validated prospects.');
        }

        $prepared = [];
        foreach ($prospects as $prospect) {
            if (! is_array($prospect) || empty($prospect['name']) || empty($prospect['funder_type']) || empty($prospect['summary']) || empty($prospect['confidence']) || empty($prospect['ask_rationale']) || empty($prospect['claims']) || ! is_array($prospect['claims']) || empty($prospect['score_signals']) || ! is_array($prospect['score_signals'])) {
                throw new UnexpectedValueException('Every prospect requires a name and evidence-backed claims.');
            }

            foreach ($prospect['claims'] as $claim) {
                if (! is_array($claim) || empty($claim['key']) || empty($claim['claim']) || empty($claim['sources']) || ! is_array($claim['sources'])) {
                    throw new UnexpectedValueException('Every factual claim requires complete evidence metadata.');
                }

                foreach ($claim['sources'] as $source) {
                    if (! is_array($source) || empty($source['title']) || empty($source['url']) || empty($source['source_type']) || empty($source['retrieved_at']) || empty($source['excerpt_or_locator'])) {
                        throw new UnexpectedValueException('Every evidence source requires title, URL, type, retrieval time, and locator.');
                    }
                }
                if (isset($claim['status']) && ! in_array($claim['status'], ['supported', 'contradicted', 'unknown', 'disqualifying'], true)) {
                    throw new UnexpectedValueException('Claim status is not recognized.');
                }
            }

            foreach (array_merge($prospect['people'] ?? [], $prospect['relationships'] ?? []) as $record) {
                if (empty($record['sources']) || ! is_array($record['sources'])) {
                    throw new UnexpectedValueException('Every person and relationship requires evidence metadata.');
                }
                foreach ($record['sources'] as $source) {
                    if (empty($source['title']) || empty($source['url']) || empty($source['source_type']) || empty($source['retrieved_at']) || empty($source['excerpt_or_locator'])) {
                        throw new UnexpectedValueException('Every person and relationship source must be complete.');
                    }
                }
            }

            $signals = $prospect['score_signals'];
            if (empty($prospect['grants']) && (($signals['historical_giving'] ?? 0) != 0 || ($signals['grant_size_fit'] ?? 0) != 0 || ($signals['recency'] ?? 0) != 0)) {
                throw new UnexpectedValueException('Grant-related score signals require verified grant records.');
            }
            if (empty($prospect['relationships']) && ($signals['relationship_strength'] ?? 0) != 0) {
                throw new UnexpectedValueException('Relationship score requires verified relationship records.');
            }

            $prepared[] = ['prospect' => $prospect, 'score' => $this->scorer->calculate($prospect['score_signals'])];
        }

        return $prepared;
    }
}
