<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Suivi des jobs d'import et d'enrichissement (§6.2, EF-04.5).
 */
class Import extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
