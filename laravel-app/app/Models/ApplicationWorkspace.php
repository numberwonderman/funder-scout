<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationWorkspace extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
