<?php

namespace App\Console\Commands;

use App\Models\Export;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purge des exports expirés (EF-07.4) : le lien meurt à 7 jours, le
 * fichier n'a plus de raison d'occuper le disque. La ligne d'export est
 * conservée (traçabilité EF-07.5) — seul file_path est nettoyé.
 */
class PurgeExpiredExportsCommand extends Command
{
    protected $signature = 'fbde:exports:purge';

    protected $description = 'Supprime les fichiers des exports dont le lien a expiré';

    public function handle(): int
    {
        $purged = 0;

        Export::query()
            ->whereNotNull('file_path')
            ->where('expires_at', '<', now())
            ->each(function (Export $export) use (&$purged): void {
                Storage::disk('local')->delete($export->file_path);
                $export->update(['file_path' => null]);
                Audit::log('export.purged', $export, userId: $export->user_id);
                $purged++;
            });

        $this->info("{$purged} fichier(s) d'export purgé(s).");

        return self::SUCCESS;
    }
}
