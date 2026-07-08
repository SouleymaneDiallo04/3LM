<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\NameNormalizer;
use App\Services\Ingestion\Sirene\SireneStockConnector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import du stock SIRENE (§5.1 « Import BCE » transposé France).
 * Chaque exécution est tracée dans la table imports avec son rapport
 * de déduplication (EF-04.5).
 */
class ImportSireneCommand extends Command
{
    protected $signature = 'fbde:sirene:import
        {--unites= : Chemin du fichier StockUniteLegale (csv ou zip)}
        {--etablissements= : Chemin du fichier StockEtablissement (csv ou zip)}
        {--department= : Limiter l\'import à un département (ex. 75, 2A, 971)}';

    protected $description = 'Importe les unités légales et établissements SIRENE (dédup à l\'ingestion)';

    public function handle(IngestionService $ingestion, NameNormalizer $normalizer): int
    {
        $unites = $this->option('unites');
        $etablissements = $this->option('etablissements');

        if (! $unites || ! $etablissements) {
            $this->error('Les options --unites et --etablissements sont requises.');

            return self::FAILURE;
        }

        foreach ([$unites, $etablissements] as $path) {
            if (! is_readable($path)) {
                $this->error("Fichier illisible : {$path}");

                return self::FAILURE;
            }
        }

        $department = $this->option('department') ?: null;

        $connector = new SireneStockConnector(
            $normalizer,
            $unites,
            $etablissements,
            $department,
        );

        // Import par département : le fichier des unités légales est
        // national — seules celles ayant un établissement dans le
        // département sont importées (pré-scan des SIREN).
        if ($department !== null) {
            $this->info("Pré-scan des SIREN du département {$department}…");
            $sirens = $connector->collectSirens();
            $connector->setSirenWhitelist($sirens);
            $this->info(count($sirens).' unités légales concernées.');
        }

        $import = Import::create([
            'source' => 'sirene',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Import des unités légales…');
            $companyStats = $ingestion->importCompanies($connector);
            $this->table(
                ['créées', 'mises à jour', 'rejetées'],
                [[$companyStats['created'], $companyStats['updated'], $companyStats['rejected']]],
            );

            $this->info('Import des établissements…');
            $establishmentStats = $ingestion->importEstablishments($connector);
            $this->table(
                ['créés', 'mis à jour', 'rejetés', 'géolocalisés'],
                [[
                    $establishmentStats['created'],
                    $establishmentStats['updated'],
                    $establishmentStats['rejected'],
                    $establishmentStats['geocoded'],
                ]],
            );

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => [
                    'companies' => $companyStats,
                    'establishments' => $establishmentStats,
                ],
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
