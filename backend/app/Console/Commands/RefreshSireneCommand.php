<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Re-import mensuel du stock SIRENE (§2.1) : l'INSEE publie les fichiers
 * stock en début de mois. Les URLs sont résolues via l'API data.gouv
 * (les liens files.data.gouv.fr directs ne sont pas fiables — constaté
 * au J2), les fichiers téléchargés en flux, puis l'import enchaîné.
 */
class RefreshSireneCommand extends Command
{
    protected $signature = 'fbde:sirene:refresh';

    protected $description = 'Télécharge le dernier stock SIRENE et lance l\'import';

    public function handle(): int
    {
        $dataset = Http::timeout(60)
            ->get(config('fbde.sirene_dataset_api'))
            ->throw()
            ->json();

        $unites = $this->resourceUrl($dataset, 'StockUniteLegale');
        $etablissements = $this->resourceUrl($dataset, 'StockEtablissement');

        if ($unites === null || $etablissements === null) {
            $this->error('Ressources StockUniteLegale/StockEtablissement introuvables sur data.gouv.');

            return self::FAILURE;
        }

        Storage::disk('local')->makeDirectory('sirene');
        $unitesPath = $this->download($unites);
        $etablissementsPath = $this->download($etablissements);

        return $this->call('fbde:sirene:import', array_filter([
            '--unites' => $unitesPath,
            '--etablissements' => $etablissementsPath,
            '--department' => config('fbde.import_department'),
        ]));
    }

    /**
     * Première ressource dont le titre commence par le préfixe donné —
     * data.gouv liste la plus récente en tête.
     *
     * @param  array<string, mixed>  $dataset
     */
    private function resourceUrl(array $dataset, string $prefix): ?string
    {
        foreach ($dataset['resources'] ?? [] as $resource) {
            if (str_starts_with((string) ($resource['title'] ?? ''), $prefix)) {
                return $resource['url'];
            }
        }

        return null;
    }

    /** Télécharge en flux vers storage/app/sirene/ et renvoie le chemin absolu. */
    private function download(string $url): string
    {
        $relative = 'sirene/'.basename(parse_url($url, PHP_URL_PATH));
        $absolute = Storage::disk('local')->path($relative);

        $this->info("Téléchargement de {$url}…");
        Http::timeout(3600)->sink($absolute)->get($url)->throw();

        return $absolute;
    }
}
