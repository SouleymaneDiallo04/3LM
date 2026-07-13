<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Scoring\ScoringService;
use Illuminate\Support\Facades\DB;

/**
 * Plus-proches-voisins cosinus sur les embeddings pgvector (EF-08.3) : sert
 * de base à « prospects similaires ». Garde RGPD partagée (ProspectGuard) et
 * exclusions (soi-même, même unité légale, non-diffusible, liste d'exclusion).
 */
class SimilarProspects
{
    public function __construct(
        private readonly ProspectGuard $guard,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * Plus-proches-voisins cosinus. Lève AiGenerationDenied si la fiche est
     * non-diffusible ; lève NotIndexed si elle n'a pas encore d'embedding.
     *
     * @return list<array{id:int,siret:string,name:?string,city:?string,naf_code:?string,commercial_tier:?string,proximity:float}>
     */
    public function for(Establishment $establishment, int $limit = 8): array
    {
        $this->guard->assertAllowed($establishment);

        $self = DB::selectOne('SELECT embedding::text AS vec FROM establishments WHERE id = ?', [$establishment->id]);
        if ($self === null || $self->vec === null) {
            throw new Exceptions\NotIndexed('Fiche pas encore indexée pour la similarité.');
        }

        $rows = DB::select(
            'SELECT e.id, e.siret, e.name, e.city, e.naf_code, e.commercial_score,
                    (e.embedding <=> ?::vector) AS distance
             FROM establishments e
             JOIN companies c ON c.id = e.company_id
             WHERE e.embedding IS NOT NULL
               AND e.id <> ?
               AND e.company_id <> ?
               AND c.is_diffusible = true
               AND NOT EXISTS (
                 SELECT 1 FROM exclusion_list x
                 WHERE (x.identifier_type = \'siret\' AND x.identifier_value = e.siret)
                    OR (x.identifier_type = \'siren\' AND x.identifier_value = c.siren))
             ORDER BY e.embedding <=> ?::vector
             LIMIT ?',
            [$self->vec, $establishment->id, $establishment->company_id, $self->vec, $limit],
        );

        return array_map(fn ($r) => [
            'id' => (int) $r->id,
            'siret' => $r->siret,
            'name' => $r->name,
            'city' => $r->city,
            'naf_code' => $r->naf_code,
            'commercial_tier' => $this->scoring->tier($r->commercial_score),
            // distance cosinus 0..2 → proximité 1..0
            'proximity' => round(1 - ((float) $r->distance) / 2, 3),
        ], $rows);
    }
}
