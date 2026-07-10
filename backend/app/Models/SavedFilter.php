<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filtre nommé réutilisable (EF-03.4) : privé par défaut, partageable
 * avec toute l'équipe via is_shared.
 */
class SavedFilter extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
