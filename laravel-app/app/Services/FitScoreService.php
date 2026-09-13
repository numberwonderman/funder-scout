<?php

namespace App\Services;

use InvalidArgumentException;

class FitScoreService
{
    public const WEIGHTS = [
        'cause_alignment' => ['label' => 'Cause alignment', 'weight' => 30],
        'historical_giving' => ['label' => 'Comparable giving', 'weight' => 20],
        'geographic_fit' => ['label' => 'Geographic fit', 'weight' => 15],
        'grant_size_fit' => ['label' => 'Grant-size fit', 'weight' => 15],
        'recency' => ['label' => 'Giving recency', 'weight' => 10],
        'relationship_strength' => ['label' => 'Relationship strength', 'weight' => 10],
    ];

    public function calculate(array $signals): array
    {
        $components = [];
        foreach (self::WEIGHTS as $key => $definition) {
            if (! array_key_exists($key, $signals) || ! is_numeric($signals[$key])) {
                throw new InvalidArgumentException("Missing score signal: {$key}");
            }
            $signal = (float) $signals[$key];
            if ($signal < 0 || $signal > 1) {
                throw new InvalidArgumentException("Score signal {$key} must be between 0 and 1");
            }
            $components[] = ['key' => $key, 'label' => $definition['label'], 'signal' => $signal, 'weight' => $definition['weight'], 'points' => (int) round($signal * $definition['weight'])];
        }

        return ['total' => array_sum(array_column($components, 'points')), 'components' => $components];
    }
}
