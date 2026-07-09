<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des recherches (EF-01.6, §6.2).
 */
class Search extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
