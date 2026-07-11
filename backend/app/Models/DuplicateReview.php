<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paire de doublons probables en revue manuelle (EF-04.2) : deux
 * établissements de SIREN différents, jugés identiques par un humain
 * (fusion) ou distincts. La fusion automatique reste réservée à
 * l'identifiant fort — ici, c'est une décision humaine tracée.
 */
class DuplicateReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishmentA(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_a_id');
    }

    /** @return BelongsTo<Establishment, $this> */
    public function establishmentB(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_b_id');
    }
}
