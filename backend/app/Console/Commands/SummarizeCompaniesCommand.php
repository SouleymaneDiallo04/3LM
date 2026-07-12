<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\CompanySummarizer;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use App\Services\Ai\Prompts;
use Illuminate\Console\Command;
use Throwable;

/** Pré-génération des résumés IA (EF-08.2) en masse — tracée, résiliente. */
class SummarizeCompaniesCommand extends Command
{
    protected $signature = 'fbde:ai:summarize
        {--department= : Limiter à un département}
        {--limit=100 : Nombre maximal de fiches}';

    protected $description = 'Pré-génère les résumés IA des établissements diffusibles';

    public function handle(CompanySummarizer $summarizer): int
    {
        $import = Import::create([
            'source' => 'ai_summary', 'status' => 'running', 'started_at' => now(),
        ]);

        $stats = ['generated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            Establishment::query()
                ->where('status', 'active')
                // is_diffusible est porté par l'unité légale (companies).
                ->whereHas('company', fn ($q) => $q->where('is_diffusible', true))
                ->whereNull('ai_summary')
                ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
                ->with('company')
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (Establishment $e) use ($summarizer, &$stats): void {
                    try {
                        $e->update([
                            'ai_summary' => $summarizer->summarize($e),
                            'ai_summary_version' => Prompts::VERSION,
                            'ai_summary_at' => now(),
                        ]);
                        $stats['generated']++;
                    } catch (AiGenerationDenied) {
                        $stats['skipped']++;
                    } catch (Throwable) {
                        $stats['errors']++;
                    }
                });

            $this->table(['générés', 'ignorés', 'erreurs'],
                [[$stats['generated'], $stats['skipped'], $stats['errors']]]);

            $import->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);

            throw $e;
        }

        return self::SUCCESS;
    }
}
