<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prospect extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence_gaps' => 'array', 'next_actions' => 'array', 'possible_disqualifiers' => 'array', 'deadline' => 'date', 'rolling_deadline' => 'boolean', 'last_verified_at' => 'datetime', 'opportunity_value' => 'float'];
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(ProspectPerson::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(Relationship::class);
    }

    public function scoreComponents(): HasMany
    {
        return $this->hasMany(ScoreComponent::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ProspectFeedback::class);
    }

    public function workspace()
    {
        return $this->hasOne(ApplicationWorkspace::class);
    }

    public function pathToMoneySteps(): HasMany
    {
        return $this->hasMany(PathToMoneyStep::class)->orderBy('sequence');
    }

    public function researchTasks(): HasMany
    {
        return $this->hasMany(ResearchTask::class)->orderByDesc('information_value');
    }
}
