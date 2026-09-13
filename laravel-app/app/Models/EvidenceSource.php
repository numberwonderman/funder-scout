<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['retrieved_at' => 'datetime', 'publication_date' => 'date', 'source_last_modified' => 'datetime', 'is_archived' => 'boolean'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
