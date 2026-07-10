<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Scoring\ScoringService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recalcul des scores (EF-08.1) : indice de réputation puis score
 * commercial, 100 % règles versionnées. Tracé dans imports.
 */
class ScoreEstablishmentsCommand extends Command
{
    protected $signature = 'fbde:score
        {--department= : Limiter à un département (ex. 33)}
        {--status=active : active, ceased ou all}';

    protected $description = 'Recalcule l\'indice de réputation et le score commercial des établissements';

    public function handle(ScoringService $scoring): int
    {
        $import = Import::create([
            'source' => 'scoring',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $scored = 0;

        try {
            Establishment::query()
                ->when($this->option('status') !== 'all', fn ($q) => $q->where('status', $this->option('status')))
                ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
                ->chunkById(500, function ($establishments) use ($scoring, &$scored): void {
                    foreach ($establishments as $establishment) {
                        $scoring->score($establishment);
                        $scored++;
                    }
                });

            $this->info("{$scored} fiche(s) scorée(s) — pondérations v".config('fbde.scoring.version').'.');

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => ['scored' => $scored, 'weights_version' => config('fbde.scoring.version')],
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
