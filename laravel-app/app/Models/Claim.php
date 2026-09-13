<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json', 'verified_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceSource::class);
    }
}
