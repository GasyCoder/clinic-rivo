<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email of an account created by an administrator.
 *
 * Not "Reset your password": nobody forgot anything. The account was just
 * opened, it has no password the person knows, and they must choose their
 * own before the first login — so the email says who they are in the
 * application, which site, and what to do, with the security rules up front.
 */
class AccountInvitationNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'welcome' => 1,
        ]);
        $expiresInHours = (int) round(config('auth.passwords.invitations.expire', 4320) / 60);
        $clinic = config('app.name');
        $site = config('rivo.site.name');

        return (new MailMessage)
            ->subject("Bienvenue sur {$clinic} — activez votre compte")
            ->view(
                ['html' => 'emails.account-invitation', 'text' => 'emails.account-invitation-text'],
                [
                    'name' => $notifiable->name,
                    'email' => $notifiable->email,
                    'role' => $notifiable->role?->name,
                    'profile' => $notifiable->professionalProfile?->name,
                    'clinic' => $clinic,
                    'site' => $site,
                    'url' => $url,
                    'expiresInHours' => $expiresInHours,
                    'loginUrl' => route('login'),
                ],
            );
    }
}
