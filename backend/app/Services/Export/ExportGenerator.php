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
 * Génération des fichiers d'export (EF-07.1, EF-07.2, EF-07.3, EF-07.4).
 * Le résultat filtré est parcouru en flux (chunkById) et écrit ligne à
 * ligne quel que soit le format — la mémoire reste constante quel que
 * soit le volume (§9.1 : 50 000 lignes < 3 min).
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

        $rows = match ($export->format) {
            'csv', 'xlsx' => $this->writeSpreadsheet($export, $absolute, $columns),
            'json' => $this->writeJson($export, $absolute, $columns),
            'xml' => $this->writeXml($export, $absolute, $columns),
            'sql' => $this->writeSql($export, $absolute, $columns),
        };

        $export->update([
            'status' => 'completed',
            'file_path' => $relative,
            'rows_count' => $rows,
            'expires_at' => now()->addDays(self::LINK_LIFETIME_DAYS),
        ]);
    }

    /** Parcourt le résultat filtré en flux et applique $write à chaque ligne. */
    private function stream(Export $export, callable $write): int
    {
        $rows = 0;

        $this->search->buildQuery($export->filters)
            ->reorder('establishments.id')
            ->chunkById(self::CHUNK, function ($establishments) use ($write, &$rows): void {
                foreach ($establishments as $establishment) {
                    $write($establishment);
                    $rows++;
                }
            }, 'establishments.id', 'id');

        return $rows;
    }

    /** @param list<string> $columns */
    private function writeSpreadsheet(Export $export, string $absolute, array $columns): int
    {
        $writer = $export->format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($absolute);
        $writer->addRow(Row::fromValues($columns));

        $rows = $this->stream($export, function (Establishment $e) use ($writer, $columns): void {
            $writer->addRow(Row::fromValues($this->extract($e, $columns)));
        });

        $writer->close();

        return $rows;
    }

    /** @param list<string> $columns */
    private function writeJson(Export $export, string $absolute, array $columns): int
    {
        $handle = fopen($absolute, 'wb');
        fwrite($handle, '[');

        $rows = $this->stream($export, function (Establishment $e) use ($handle, $columns): void {
            static $first = true;
            $record = array_combine($columns, $this->extract($e, $columns));
            fwrite($handle, ($first ? '' : ',')."\n".json_encode($record, JSON_UNESCAPED_UNICODE));
            $first = false;
        });

        fwrite($handle, "\n]\n");
        fclose($handle);

        return $rows;
    }

    /** @param list<string> $columns */
    private function writeXml(Export $export, string $absolute, array $columns): int
    {
        $handle = fopen($absolute, 'wb');
        fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<etablissements>\n");

        $rows = $this->stream($export, function (Establishment $e) use ($handle, $columns): void {
            $fields = '';
            foreach (array_combine($columns, $this->extract($e, $columns)) as $tag => $value) {
                $fields .= sprintf(
                    '    <%1$s>%2$s</%1$s>'."\n",
                    $tag,
                    htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                );
            }
            fwrite($handle, "  <etablissement>\n{$fields}  </etablissement>\n");
        });

        fwrite($handle, "</etablissements>\n");
        fclose($handle);

        return $rows;
    }

    /** @param list<string> $columns */
    private function writeSql(Export $export, string $absolute, array $columns): int
    {
        $handle = fopen($absolute, 'wb');

        $columnList = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns));
        fwrite($handle, '-- Export FBDE du '.now()->toIso8601String()."\n");
        fwrite($handle, "CREATE TABLE IF NOT EXISTS etablissements (\n    "
            .implode(",\n    ", array_map(fn (string $c): string => '"'.$c.'" TEXT', $columns))
            ."\n);\n\n");

        $rows = $this->stream($export, function (Establishment $e) use ($handle, $columns, $columnList): void {
            $values = implode(', ', array_map(
                fn ($v): string => $v === null ? 'NULL' : "'".str_replace("'", "''", (string) $v)."'",
                $this->extract($e, $columns),
            ));
            fwrite($handle, "INSERT INTO etablissements ({$columnList}) VALUES ({$values});\n");
        });

        fclose($handle);

        return $rows;
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
