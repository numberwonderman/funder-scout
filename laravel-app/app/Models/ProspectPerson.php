<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProspectPerson extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sources' => 'array'];
    }
}
