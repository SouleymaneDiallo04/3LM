<?php

namespace App\Notifications;

use App\Models\Import;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Échec d'import SIRENE (EF-10.4 « erreurs de job ») : les administrateurs
 * sont prévenus — un import mensuel raté sans alerte, c'est une base qui
 * vieillit en silence.
 */
class SireneImportFailed extends Notification
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
            'error' => $this->import->error,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('FBDE — échec de l\'import SIRENE')
            ->greeting("Bonjour {$notifiable->name},")
            ->line(sprintf(
                'L\'import SIRENE #%d a échoué : %s',
                $this->import->id,
                $this->import->error ?? 'raison inconnue',
            ))
            ->line('Consultez les journaux puis relancez « php artisan fbde:sirene:refresh ».')
            ->salutation('FBDE');
    }
}
