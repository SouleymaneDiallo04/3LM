<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\Enrichment\EnrichmentService;
use App\Services\Enrichment\Osm\OverpassConnector;
use App\Services\Ingestion\NameNormalizer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enrichissement OSM (§5.4, phase 4) : téléphone, site web, horaires
 * depuis Overpass — tracé dans imports comme tout traitement de données.
 */
class EnrichFromOsmCommand extends Command
{
    protected $signature = 'fbde:osm:enrich
        {--department= : Département à enrichir (ex. 33) — requis}';

    protected $description = 'Enrichit les établissements depuis OpenStreetMap (Overpass)';

    public function handle(EnrichmentService $enrichment, NameNormalizer $normalizer): int
    {
        $department = $this->option('department');

        if (! $department) {
            $this->error('L\'option --department est requise (une zone Overpass à la fois).');

            return self::FAILURE;
        }

        $import = Import::create([
            'source' => 'osm',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info("Interrogation Overpass pour le département {$department}…");
            $stats = $enrichment->enrich(new OverpassConnector($normalizer, $department), 'osm');

            $this->table(
                ['appariés', 'non appariés', 'champs complétés'],
                [[$stats['matched'], $stats['unmatched'], $stats['updated_fields']]],
            );

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => $stats,
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
