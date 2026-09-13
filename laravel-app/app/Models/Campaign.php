<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['board_members' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class);
    }
}
