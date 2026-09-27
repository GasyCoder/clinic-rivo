<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * ADR-197 — une notification rangée dans la boîte d'un compte (table
 * `notifications`), sur le déploiement où il se connecte. Toutes décrivent la
 * même forme — catégorie, titre, texte, lien, icône, ton —, que la cloche et la
 * page « Notifications » lisent sans rien interpréter.
 *
 * Aucun mot de passe, aucune donnée clinique : une notification dit qu'il y a
 * quelque chose à voir, et où. Le contenu reste derrière le lien, gardé par ses
 * propres droits.
 */
abstract class InboxNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, category: string, title: string, body: string, url: ?string, icon: string, tone: string, meta?: array<string, mixed>}
     */
    abstract public function payload(): array;

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }
}
