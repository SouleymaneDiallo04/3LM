<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Géocodage BAN des établissements sans coordonnées (correctif 5).
 * Chaque exécution est tracée dans imports avec son taux d'appariement.
 */
class GeocodeEstablishmentsCommand extends Command
{
    protected $signature = 'fbde:geocode
        {--department= : Limiter à un département (ex. 33)}
        {--limit= : Plafond de fiches traitées}';

    protected $description = 'Géocode via la BAN les établissements sans coordonnées';

    public function handle(GeocodingService $service): int
    {
        $import = Import::create([
            'source' => 'ban',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $stats = $service->geocodeMissing(
                $this->option('department') ?: null,
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
            );

            $rate = $stats['candidates'] > 0
                ? round($stats['geocoded'] / $stats['candidates'] * 100, 1)
                : 0.0;

            $this->table(
                ['candidats', 'géocodés', 'score insuffisant', 'non appariés', 'taux'],
                [[
                    $stats['candidates'], $stats['geocoded'],
                    $stats['below_threshold'], $stats['unmatched'], $rate.' %',
                ]],
            );

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => [...$stats, 'rate_percent' => $rate],
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
