<?php

namespace App\Console\Commands;

use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Détection des doublons de second niveau (EF-04.2) : paires
 * d'établissements actifs de SIREN DIFFÉRENTS, même ville, dénominations
 * quasi identiques (trigramme ≥ 0,85). La fusion automatique reste
 * réservée à l'identifiant fort (décision actée) : ces paires partent en
 * revue manuelle (duplicate_reviews).
 */
class DetectDuplicatesCommand extends Command
{
    private const MIN_SIMILARITY = 0.85;

    protected $signature = 'fbde:duplicates:detect
        {--department= : Limiter à un département (ex. 33)}';

    protected $description = 'Signale les doublons probables inter-SIREN pour revue manuelle';

    public function handle(): int
    {
        $import = Import::create([
            'source' => 'duplicates',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // Auto-jointure bornée : même ville, id_a < id_b (paire unique),
            // SIREN différents. L'opérateur % s'appuie sur l'index GIN
            // trigramme (le seuil de session le pilote) — sans lui, la
            // jointure serait quadratique sur les grandes villes.
            DB::statement('SET pg_trgm.similarity_threshold = '.self::MIN_SIMILARITY);

            $params = ['threshold' => self::MIN_SIMILARITY];
            $departmentClause = '';

            if ($this->option('department') !== null) {
                $departmentClause = 'AND a.department_code = :department';
                $params['department'] = $this->option('department');
            }

            $flagged = DB::affectingStatement(<<<SQL
                INSERT INTO duplicate_reviews
                    (establishment_a_id, establishment_b_id, similarity, status, created_at, updated_at)
                SELECT a.id, b.id,
                       similarity(a.normalized_name, b.normalized_name),
                       'pending', now(), now()
                FROM establishments a
                JOIN establishments b
                  ON b.postal_code = a.postal_code
                 AND b.city = a.city
                 AND b.id > a.id
                 AND b.company_id <> a.company_id
                 AND left(b.siret, 9) <> left(a.siret, 9)
                 AND b.normalized_name % a.normalized_name
                 AND similarity(a.normalized_name, b.normalized_name) >= :threshold
                WHERE a.status = 'active' AND b.status = 'active'
                  AND a.deleted_at IS NULL AND b.deleted_at IS NULL
                  {$departmentClause}
                ON CONFLICT (establishment_a_id, establishment_b_id) DO NOTHING
                SQL, $params);

            $this->info("{$flagged} paire(s) suspecte(s) envoyée(s) en revue manuelle.");

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => ['flagged' => $flagged],
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return self::SUCCESS;
    }
}
