<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-202 — « Votre compte a été validé » : envoyé dans la boîte professionnelle
 * de l'employé à sa première connexion. En file d'attente : un serveur d'envoi lent
 * ne retient jamais la connexion (même raison que SendUserInvitationJob).
 *
 * Aucun mot de passe : il le connaît, il vient de le choisir.
 */
class AccountActivatedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $activatedAt) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $clinic = config('app.name');
        $notifiable->loadMissing(['role:id,name', 'professionalProfile:id,name']);

        return (new MailMessage)
            ->subject("Votre compte {$clinic} est validé")
            ->view(
                ['html' => 'emails.account-activated', 'text' => 'emails.account-activated-text'],
                [
                    'name' => $notifiable->name,
                    'email' => $notifiable->email,
                    'role' => $notifiable->role?->name,
                    'profile' => $notifiable->professionalProfile?->name,
                    'clinic' => $clinic,
                    'site' => config('rivo.site.name'),
                    'activatedAt' => $this->activatedAt,
                    'loginUrl' => route('login'),
                    'profileUrl' => url('/profil'),
                ],
            );
    }
}
