<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeEdge extends Model
{
    protected $guarded = [];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
