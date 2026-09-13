<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['program_areas' => 'array', 'populations_served' => 'array', 'desired_funding_categories' => 'array', 'needs' => 'array', 'prefers_unrestricted' => 'boolean', 'keywords' => 'array', 'exclusions' => 'array', 'enrichment_suggestions' => 'array', 'enrichment_sources' => 'array', 'enrichment_started_at' => 'datetime', 'enriched_at' => 'datetime'];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
