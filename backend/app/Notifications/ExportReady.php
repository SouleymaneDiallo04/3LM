<?php

namespace App\Notifications;

use App\Models\Export;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Export prêt (EF-10.4) : interface + email au propriétaire.
 * Envoyée depuis le job d'export déjà en tâche de fond — pas de
 * ShouldQueue, un second passage en file n'apporterait que de la latence.
 */
class ExportReady extends Notification
{
    public function __construct(private readonly Export $export) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'export_id' => $this->export->id,
            'format' => $this->export->format,
            'rows_count' => $this->export->rows_count,
            'expires_at' => $this->export->expires_at?->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("FBDE — votre export {$this->export->format} est prêt")
            ->greeting("Bonjour {$notifiable->name},")
            ->line(sprintf(
                'Votre export %s de %s lignes est prêt au téléchargement.',
                strtoupper((string) $this->export->format),
                number_format((int) $this->export->rows_count, 0, ',', ' '),
            ))
            ->line('Le lien expire 7 jours après la génération.')
            ->salutation('FBDE');
    }
}
