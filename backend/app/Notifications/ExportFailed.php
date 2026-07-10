<?php

namespace App\Notifications;

use App\Models\Export;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Échec d'export (EF-10.4 « erreurs de job ») : le propriétaire est
 * prévenu en interface et par email, avec la raison tracée.
 */
class ExportFailed extends Notification
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
            'error' => $this->export->error,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("FBDE — échec de votre export {$this->export->format}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line(sprintf(
                'Votre export %s n\'a pas pu être généré : %s',
                strtoupper((string) $this->export->format),
                $this->export->error ?? 'raison inconnue',
            ))
            ->line('Relancez-le depuis l\'écran de recherche ; contactez un administrateur si l\'échec persiste.')
            ->salutation('FBDE');
    }
}
