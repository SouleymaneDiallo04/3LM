<?php

namespace App\Notifications;

use App\Models\Import;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Import SIRENE terminé (EF-10.4) : interface + email aux administrateurs.
 * Envoyée depuis la commande CLI — pas de ShouldQueue (déjà hors requête).
 */
class SireneImportFinished extends Notification
{
    public function __construct(private readonly Import $import) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'import_id' => $this->import->id,
            'source' => $this->import->source,
            'status' => $this->import->status,
            'stats' => $this->import->stats,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $establishments = $this->import->stats['establishments'] ?? [];

        return (new MailMessage)
            ->subject('FBDE — import SIRENE terminé')
            ->greeting("Bonjour {$notifiable->name},")
            ->line(sprintf(
                'Import SIRENE #%d terminé : %s créés, %s mis à jour, %s rejetés.',
                $this->import->id,
                number_format((int) ($establishments['created'] ?? 0), 0, ',', ' '),
                number_format((int) ($establishments['updated'] ?? 0), 0, ',', ' '),
                number_format((int) ($establishments['rejected'] ?? 0), 0, ',', ' '),
            ))
            ->salutation('FBDE');
    }
}
