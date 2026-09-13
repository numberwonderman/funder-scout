<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreComponent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signal' => 'float'];
    }
}
