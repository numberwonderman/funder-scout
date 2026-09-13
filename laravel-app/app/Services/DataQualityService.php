<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Organization;
use App\Models\PathToMoneyStep;
use App\Models\Prospect;

class DataQualityService
{
    /** @return array<string, int|float> */
    public function metrics(Organization $organization): array
    {
        $prospects = Prospect::whereHas('researchRun.campaign', fn ($query) => $query->whereBelongsTo($organization));
        $total = (clone $prospects)->count();
        if ($total === 0) {
            return ['total' => 0, 'primary_verified' => 0, 'complete_paths' => 0, 'unknown_edges' => 0, 'contradictions' => 0, 'north_star' => 0];
        }

        $primary = (clone $prospects)->whereHas('claims.evidence', fn ($query) => $query->where('source_tier', 1))->count();
        $complete = (clone $prospects)->where('path_completeness', '>=', 78)->where('path_to_money_rating', '!=', 'ineligible')->count();

        return ['total' => $total, 'primary_verified' => round($primary / $total * 100), 'complete_paths' => $complete, 'unknown_edges' => PathToMoneyStep::whereHas('prospect.researchRun.campaign', fn ($query) => $query->whereBelongsTo($organization))->where('status', 'unknown')->count(), 'contradictions' => Claim::whereHas('prospect.researchRun.campaign', fn ($query) => $query->whereBelongsTo($organization))->whereIn('status', ['contradicted', 'disqualifying'])->count(), 'north_star' => round($complete / $total * 100)];
    }
}
