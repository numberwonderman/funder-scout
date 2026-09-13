<?php

namespace App\Models;

use App\Enums\ResearchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ResearchStatus::class, 'is_demo' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'raw_agent_payload' => 'array'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class)->orderByDesc('fit_score');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }
}
