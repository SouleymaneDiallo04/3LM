<?php

namespace App\Services\Export;

use App\Models\Establishment;
use App\Models\Export;
use App\Services\Catalog\EstablishmentSearch;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Génération des fichiers d'export (EF-07.1, EF-07.3, EF-07.4).
 * Le résultat filtré est parcouru en flux (chunkById) et écrit ligne à
 * ligne — la mémoire reste constante quel que soit le volume (§9.1 :
 * 50 000 lignes < 3 min).
 */
class ExportGenerator
{
    private const CHUNK = 1000;

    private const LINK_LIFETIME_DAYS = 7;

    /** Colonnes exportables (EF-07.3) : libellé => extracteur. */
    private const COLUMNS = [
        'siret' => 'siret',
        'siren' => 'company.siren',
        'nom' => 'name',
        'denomination_legale' => 'company.legal_name',
        'forme_juridique' => 'company.legal_form',
        'statut' => 'status',
        'code_naf' => 'naf_code',
        'effectifs' => 'employee_range',
        'adresse' => 'address_line',
        'code_postal' => 'postal_code',
        'ville' => 'city',
        'departement' => 'department_code',
        'telephone' => 'phone',
        'site_web' => 'website',
        'email' => 'email',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'score_commercial' => 'commercial_score',
    ];

    public function __construct(private readonly EstablishmentSearch $search) {}

    /** @return list<string> */
    public static function availableColumns(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** Génère le fichier de l'export et met à jour son suivi. */
    public function generate(Export $export): void
    {
        $export->update(['status' => 'running']);

        $columns = $export->columns ?: self::availableColumns();
        $relative = sprintf('exports/%d_%s.%s', $export->id, now()->format('Ymd_His'), $export->format);
        $absolute = Storage::disk('local')->path($relative);

        Storage::disk('local')->makeDirectory('exports');

        $writer = $export->format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($absolute);
        $writer->addRow(Row::fromValues($columns));

        $rows = 0;

        $this->search->buildQuery($export->filters)
            ->reorder('establishments.id')
            ->chunkById(self::CHUNK, function ($establishments) use ($writer, $columns, &$rows): void {
                foreach ($establishments as $establishment) {
                    $writer->addRow(Row::fromValues($this->extract($establishment, $columns)));
                    $rows++;
                }
            }, 'establishments.id', 'id');

        $writer->close();

        $export->update([
            'status' => 'completed',
            'file_path' => $relative,
            'rows_count' => $rows,
            'expires_at' => now()->addDays(self::LINK_LIFETIME_DAYS),
        ]);
    }

    /** @param list<string> $columns
     * @return list<string|int|float|null> */
    private function extract(Establishment $establishment, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $path = self::COLUMNS[$column] ?? null;
            $values[] = $path === null ? null : data_get($establishment, $path);
        }

        return $values;
    }
}
