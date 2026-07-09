<?php

namespace App\Jobs;

use App\Models\Export;
use App\Notifications\ExportReady;
use App\Services\Export\ExportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Export en tâche de fond (EF-07.4) : la génération ne bloque jamais
 * la requête HTTP ; l'échec est tracé sur la ligne d'export.
 */
class GenerateExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly Export $export) {}

    public function handle(ExportGenerator $generator): void
    {
        $generator->generate($this->export);

        // Export prêt (EF-10.4) : le propriétaire est prévenu en interface
        // et par email dès que le fichier est téléchargeable.
        if ($this->export->refresh()->status === 'completed') {
            $this->export->user->notify(new ExportReady($this->export));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Échec inconnu',
        ]);
    }
}
