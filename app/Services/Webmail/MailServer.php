<?php

namespace App\Services\Webmail;

use Symfony\Component\Mime\Email;

/**
 * ADR-194 — une boîte de messagerie ouverte : ce que RIVO sait lui demander.
 *
 * Deux implémentations : `ImapMailServer` (la vraie boîte chez l'hébergeur, IMAP +
 * SMTP) et `FakeMailServer` (en mémoire, pour les tests). Les messages restent
 * sur le serveur : rien de ce que renvoie ce contrat n'est rangé dans la base de
 * RIVO (arbitrage du propriétaire).
 *
 * Toute panne du serveur lève `WebmailUnavailable` ; un mot de passe refusé,
 * `WebmailAuthenticationFailed`.
 *
 * Formes échangées (tableaux simples, datés en ISO 8601) :
 *
 *   dossier   path, name, delimiter, attributes (list<string>), total, unseen
 *   résumé    uid, from {name, email}, to list<{name, email}>, subject, date,
 *             seen, flagged, answered, draft, keywords list<string>, has_attachments
 *   message   résumé + cc, bcc, reply_to, html, text, message_id, in_reply_to,
 *             references, attachments list<{part, name, type, size, cid, inline}>
 */
interface MailServer
{
    /**
     * Les dossiers de la boîte. Sans compteurs (`$withCounts = false`), seulement
     * l'arborescence : c'est ce qu'il faut pour trouver un chemin, et cela évite un
     * aller-retour vers le serveur.
     *
     * @return list<array{path: string, name: string, delimiter: string, attributes: list<string>, total: int, unseen: int}>
     */
    public function folders(bool $withCounts = true): array;

    /**
     * Ce que la page va lire ensuite — la recherche d'une liste, ou un message et son
     * marquage « lu » —, à envoyer avec les compteurs des dossiers (`folders()`) dans
     * le même aller-retour. Une simple annonce : sans elle, tout se lit comme avant.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     */
    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false): void;

    /**
     * Une page de messages, les plus récents d'abord.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array;

    /**
     * Les identifiants des messages qui répondent aux critères, du plus récent au plus ancien.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     * @return list<int>
     */
    public function uids(string $folder, array $criteria = []): array;

    /** @return array<string, mixed>|null Le message complet ; ne le marque pas lu. */
    public function message(string $folder, int $uid): ?array;

    /** @return array{name: string, type: string, content: string}|null */
    public function attachment(string $folder, int $uid, string $part): ?array;

    /** @return string|null Le message brut (RFC 822), pour le transférer ou le reprendre. */
    public function raw(string $folder, int $uid): ?string;

    /**
     * Pose ou retire un drapeau : `\Seen`, `\Flagged`, `\Answered`, ou un mot-clé
     * (libellé) sans barre oblique.
     *
     * @param  list<int>  $uids
     */
    public function flag(string $folder, array $uids, string $flag, bool $on): void;

    /** @param list<int> $uids */
    public function move(string $folder, array $uids, string $target): void;

    /** Suppression définitive : `\Deleted` puis EXPUNGE. @param list<int> $uids */
    public function delete(string $folder, array $uids): void;

    /** @param list<string> $flags */
    public function append(string $folder, string $raw, array $flags = []): void;

    public function createFolder(string $path): void;

    /** @return array{used: int, limit: int}|null En octets ; `null` si le serveur ne dit rien. */
    public function quota(): ?array;

    /** Envoie par SMTP. */
    public function send(Email $email): void;

    public function disconnect(): void;
}
