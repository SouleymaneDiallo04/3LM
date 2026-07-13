<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\CompanyEmbedder;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Console\Command;
use Throwable;

/** Pré-génération des embeddings (EF-08.3) en masse — tracée, résiliente. */
class EmbedCompaniesCommand extends Command
{
    protected $signature = 'fbde:ai:embed
        {--department= : Limiter à un département}
        {--limit=100 : Nombre maximal de fiches}';

    protected $description = 'Génère les embeddings des établissements diffusibles non indexés';

    public function handle(CompanyEmbedder $embedder): int
    {
        $import = Import::create(['source' => 'ai_embedding', 'status' => 'running', 'started_at' => now()]);
        $stats = ['generated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            Establishment::query()
                ->where('status', 'active')
                ->whereHas('company', fn ($q) => $q->where('is_diffusible', true))
                ->whereNull('embedding')
                ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
                ->with('company')
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (Establishment $e) use ($embedder, &$stats): void {
                    try {
                        $embedder->embed($e);
                        $stats['generated']++;
                    } catch (AiGenerationDenied) {
                        $stats['skipped']++;
                    } catch (Throwable) {
                        $stats['errors']++;
                    }
                });

            $this->table(['générés', 'ignorés', 'erreurs'], [[$stats['generated'], $stats['skipped'], $stats['errors']]]);
            $import->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
            throw $e;
        }

        return self::SUCCESS;
    }
}
