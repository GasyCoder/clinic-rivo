<?php

namespace App\Notifications;

/**
 * ADR-202 — la première connexion de l'employé : bienvenue, et où trouver sa
 * messagerie professionnelle, qui a reçu le même mot de passe. Si la boîte n'a
 * pas pu le recevoir, la notification le dit plutôt que d'envoyer vers une
 * messagerie qui refuserait.
 */
class WelcomeToPlatform extends InboxNotification
{
    public function __construct(
        public readonly string $brand,
        public readonly ?string $mailbox,
        public readonly bool $mailboxReady,
    ) {}

    public function payload(): array
    {
        $body = match (true) {
            $this->mailbox !== null && $this->mailboxReady => "Votre compte est activé. Votre messagerie professionnelle {$this->mailbox} s’ouvre avec le même mot de passe : consultez-la, un message de bienvenue vous y attend.",
            $this->mailbox !== null => "Votre compte est activé. Votre messagerie {$this->mailbox} n’a pas pu recevoir ce mot de passe : prévenez le RH.",
            default => 'Votre compte est activé. Bonne arrivée sur la plateforme.',
        };

        return [
            'kind' => 'account.welcome',
            'category' => 'account',
            'title' => "Bienvenue sur {$this->brand}",
            'body' => $body,
            'url' => $this->mailbox !== null && $this->mailboxReady ? '/messagerie' : '/profil',
            'icon' => 'party-popper',
            'tone' => $this->mailbox !== null && ! $this->mailboxReady ? 'warning' : 'success',
        ];
    }
}
