<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['likely_sources' => 'array', 'search_queries' => 'array', 'stop_conditions' => 'array'];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }
}
