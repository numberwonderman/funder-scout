<?php

namespace Tests\Unit;

use App\Services\FitScoreService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FitScoreServiceTest extends TestCase
{
    public function test_it_calculates_the_explainable_fixture_score(): void
    {
        $result = (new FitScoreService)->calculate(['cause_alignment' => .93, 'historical_giving' => .9, 'geographic_fit' => 1, 'grant_size_fit' => .8, 'recency' => .8, 'relationship_strength' => .8]);
        $this->assertSame(89, $result['total']);
        $this->assertCount(6, $result['components']);
        $this->assertSame(100, array_sum(array_column($result['components'], 'weight')));
    }

    public function test_it_rejects_out_of_bounds_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FitScoreService)->calculate(['cause_alignment' => 2, 'historical_giving' => 0, 'geographic_fit' => 0, 'grant_size_fit' => 0, 'recency' => 0, 'relationship_strength' => 0]);
    }
}
