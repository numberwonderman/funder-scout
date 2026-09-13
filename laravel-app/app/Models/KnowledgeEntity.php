<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeEntity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'from_entity_id');
    }
}
