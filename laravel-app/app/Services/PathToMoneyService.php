<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Prospect;

class PathToMoneyService
{
    private const STEPS = [
        ['legal_eligibility', 'Organization legally eligible?', ['tax_status', 'legal_eligibility', 'eligibility'], true, 5],
        ['geographic_eligibility', 'Geography eligible?', ['geography', 'geographic_fit', 'service_area'], true, 5],
        ['mission_alignment', 'Mission aligned?', ['mission', 'cause', 'cause_alignment'], false, 4],
        ['program_eligibility', 'Program or project eligible?', ['program', 'project', 'program_eligibility'], true, 5],
        ['eligible_use', 'Proposed use of funds allowed?', ['use_of_funds', 'expense', 'eligible_use'], true, 5],
        ['amount_realism', 'Requested amount realistic?', ['grant_amount', 'amount', 'giving'], false, 4],
        ['historical_precedent', 'Similar organizations funded?', ['historical_giving', 'prior_funding', 'recipient'], false, 3],
        ['application_available', 'Application available?', ['application', 'open_program', 'invitation'], true, 5],
        ['deadline_feasible', 'Deadline feasible?', ['deadline', 'application_round'], true, 5],
    ];

    public function evaluate(Prospect $prospect): void
    {
        $prospect->loadMissing('claims.evidence', 'grants', 'researchRun.campaign.organization');
        $known = 0;
        $disqualified = false;
        $prospect->pathToMoneySteps()->delete();
        $prospect->researchTasks()->delete();

        foreach (self::STEPS as $index => [$key, $label, $aliases, $hardGate, $impact]) {
            $claim = $prospect->claims->first(fn (Claim $claim): bool => collect($aliases)->contains(fn (string $alias): bool => str_contains(strtolower($claim->key.' '.$claim->predicate), $alias)));
            if (! $claim && $key === 'mission_alignment') {
                $claim = $prospect->claims->first();
            }
            [$status, $outcome, $rationale] = $this->classify($prospect, $key, $claim, $hardGate);
            $isDisqualifying = $hardGate && in_array($status, ['contradicted', 'disqualifying'], true);
            $known += $status !== 'unknown' ? 1 : 0;
            $disqualified = $disqualified || $isDisqualifying;
            $step = $prospect->pathToMoneySteps()->create(['sequence' => $index + 1, 'step_key' => $key, 'label' => $label, 'status' => $isDisqualifying ? 'disqualifying' : $status, 'outcome' => $outcome, 'rationale' => $rationale, 'claim_id' => $claim?->id, 'is_hard_gate' => $hardGate, 'is_disqualifying' => $isDisqualifying]);
            if ($status === 'unknown') {
                $this->createResearchTask($prospect, $step, $impact);
            }
        }

        $completeness = (int) round(($known / count(self::STEPS)) * 100);
        $rating = $disqualified ? 'ineligible' : match (true) {
            $completeness >= 78 => 'high', $completeness >= 55 => 'medium', $completeness >= 33 => 'low', default => 'unknown'
        };
        $burden = ['very_low' => 1, 'low' => 1.5, 'moderate' => 2.5, 'high' => 4, 'very_high' => 6, 'unknown' => 3][$prospect->application_effort] ?? 3;
        $value = $prospect->recommended_ask_max ? round($prospect->recommended_ask_max / $burden, 4) : null;
        $prospect->update(['path_to_money_rating' => $rating, 'path_completeness' => $completeness, 'opportunity_value' => $value, 'eligibility_status' => $disqualified ? 'ineligible' : $prospect->eligibility_status, 'opportunity_status' => $disqualified ? 'ineligible' : $prospect->opportunity_status, 'fit_score' => $disqualified ? 0 : $prospect->fit_score]);
    }

    /** @return array{string, ?string, string} */
    private function classify(Prospect $prospect, string $key, ?Claim $claim, bool $hardGate): array
    {
        if ($key === 'amount_realism' && $prospect->grants->isNotEmpty()) {
            return ['strongly_supported', 'yes', 'Verified historical awards provide an observed amount precedent.'];
        }
        if ($key === 'historical_precedent' && $prospect->grants->isNotEmpty()) {
            return ['verified', 'yes', $prospect->grants->count().' sourced historical award record(s) were found.'];
        }
        if (! $claim) {
            return ['unknown', null, 'No claim with sufficient provenance resolved this decision link.'];
        }
        if (in_array($claim->status, ['contradicted', 'disqualifying'], true)) {
            return [$claim->status, 'no', $claim->claim];
        }
        $bestTier = $claim->evidence->min('source_tier') ?? 4;
        if ($hardGate && $bestTier > 2) {
            return ['unknown', null, 'Discovery evidence exists, but a primary or strong institutional source is required for this eligibility decision.'];
        }
        $status = $bestTier === 1 ? 'verified' : ($bestTier === 2 ? 'strongly_supported' : 'inferred');

        return [$status, 'yes', $claim->claim];
    }

    private function createResearchTask(Prospect $prospect, $step, int $impact): void
    {
        $funder = $prospect->name;
        $question = rtrim($step->label, '?')." for {$prospect->researchRun->campaign->organization->name}?";
        $prospect->researchTasks()->create(['path_to_money_step_id' => $step->id, 'question' => $question, 'why_it_matters' => $step->is_hard_gate ? 'This may determine eligibility before application effort is spent.' : 'Resolving this would materially improve the recommendation.', 'likely_sources' => ['Official funder guidelines', 'Official application portal', 'Official award or annual report'], 'search_queries' => ["site:{$this->host($prospect->canonical_application_url)} {$funder} {$step->step_key}", "\"{$funder}\" {$step->step_key} guidelines", "filetype:pdf \"{$funder}\" {$step->step_key}"], 'stop_conditions' => ['A Tier 1 source explicitly resolves the question', 'Two Tier 2 sources agree', 'Bounded search budget is exhausted'], 'decision_impact' => $impact, 'uncertainty' => 5, 'resolvability' => 4, 'research_cost' => 2, 'information_value' => ($impact * 5 * 4) / 2]);
    }

    private function host(?string $url): string
    {
        return parse_url($url ?? '', PHP_URL_HOST) ?: strtolower(str_replace(' ', '', $url ?? 'funder.org'));
    }
}
