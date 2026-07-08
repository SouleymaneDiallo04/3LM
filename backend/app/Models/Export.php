<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export asynchrone (EF-07) : fichier généré en tâche de fond,
 * lien expirant à 7 jours (EF-07.4).
 */
class Export extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
