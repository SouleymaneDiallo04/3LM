<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\CompanyEmbedder;
use Illuminate\Console\Command;
use Throwable;

/** Pré-génération des embeddings (EF-08.3) en masse — tracée, résiliente. */
class EmbedCompaniesCommand extends Command
{
    protected $signature = 'fbde:ai:embed
        {--department= : Limiter à un département}
        {--limit=100 : Nombre maximal de fiches}
        {--batch=100 : Fiches envoyées par appel API (le batching rend le backfill de masse tenable)}';

    protected $description = 'Génère les embeddings des établissements diffusibles non indexés';

    public function handle(CompanyEmbedder $embedder): int
    {
        $import = Import::create(['source' => 'ai_embedding', 'status' => 'running', 'started_at' => now()]);
        $stats = ['generated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            $limit = (int) $this->option('limit');
            $batch = max(1, (int) $this->option('batch'));
            $lastId = 0;
            $processed = 0;

            while ($processed < $limit) {
                $chunk = Establishment::query()
                    ->where('status', 'active')
                    // is_diffusible est porté par l'unité légale (companies).
                    ->whereHas('company', fn ($q) => $q->where('is_diffusible', true))
                    ->whereNull('embedding')
                    ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
                    // Curseur par id : le lot suivant avance même si le précédent a échoué
                    // (sinon on rejouerait indéfiniment les mêmes fiches en erreur).
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->with('company')
                    ->limit(min($batch, $limit - $processed))
                    ->get();

                if ($chunk->isEmpty()) {
                    break;
                }

                $lastId = (int) $chunk->last()->id;
                $processed += $chunk->count();

                try {
                    $result = $embedder->embedMany($chunk);
                    $stats['generated'] += $result['generated'];
                    $stats['skipped'] += $result['skipped'];
                } catch (Throwable) {
                    // Un lot perdu n'interrompt pas le backfill.
                    $stats['errors'] += $chunk->count();
                }

                // Les cycles de références de Guzzle (un client par appel) retiennent
                // chaque réponse (~2 Mo/lot) ; le GC automatique ne se déclenche qu'à
                // 10 000 racines — jamais atteint avant l'OOM avec si peu d'objets si
                // volumineux. Collecte explicite par lot : mémoire bornée.
                gc_collect_cycles();

                if ($processed % 1000 === 0) {
                    $mem = round(memory_get_usage(true) / 1048576);
                    $this->info("… {$processed} fiches traitées ({$stats['generated']} générées, {$stats['errors']} erreurs, {$mem} Mo)");
                }
            }

            $this->table(['générés', 'ignorés', 'erreurs'], [[$stats['generated'], $stats['skipped'], $stats['errors']]]);
            $import->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
            throw $e;
        }

        return self::SUCCESS;
    }
}
