<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PathToMoneyStep extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_hard_gate' => 'boolean', 'is_disqualifying' => 'boolean'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
