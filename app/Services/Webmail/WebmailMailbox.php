<?php

namespace App\Services\Webmail;

use App\Models\WebmailLabel;
use App\Support\Webmail\EmailHtmlSanitizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;

/**
 * ADR-194 — la boîte d'un titulaire, telle que la messagerie la présente : ses
 * dossiers reconnus et nommés en français, la page d'un dossier, un message prêt à
 * lire, les actions groupées, l'envoi et les brouillons. Toutes les règles de la
 * messagerie sont ici ; les contrôleurs ne font que les appeler.
 *
 * Les dossiers spéciaux sont reconnus par leur attribut (RFC 6154), sinon par leur
 * nom — jamais créés d'avance : un dossier qui manque (Archives, Envoyés…) est créé
 * la première fois qu'il sert, à côté des autres.
 */
final class WebmailMailbox
{
    /** @var array<string, array{key: string, name: string, create: ?string, icon: string}> */
    public const ROLES = [
        'inbox' => ['key' => 'reception', 'name' => 'Boîte de réception', 'create' => null, 'icon' => 'inbox'],
        'drafts' => ['key' => 'brouillons', 'name' => 'Brouillons', 'create' => 'Drafts', 'icon' => 'drafts'],
        'sent' => ['key' => 'envoyes', 'name' => 'Envoyés', 'create' => 'Sent', 'icon' => 'sent'],
        'archive' => ['key' => 'archives', 'name' => 'Archives', 'create' => 'Archive', 'icon' => 'archive'],
        'spam' => ['key' => 'indesirables', 'name' => 'Indésirables', 'create' => 'Junk', 'icon' => 'spam'],
        'trash' => ['key' => 'corbeille', 'name' => 'Corbeille', 'create' => 'Trash', 'icon' => 'trash'],
    ];

    /** Les messages étoilés de la réception, des archives et des envoyés. */
    public const FAVORITES = 'favoris';

    private const FAVORITE_ROLES = ['inbox', 'archive', 'sent'];

    /** Au-delà, les favoris d'un dossier ne sont pas tous réunis : la limite est dite. */
    private const FAVORITES_PER_FOLDER = 200;

    private const SPECIAL_USE = ['\\sent' => 'sent', '\\drafts' => 'drafts', '\\junk' => 'spam', '\\trash' => 'trash', '\\archive' => 'archive'];

    private const NAMES = [
        'sent' => ['sent', 'sent items', 'sent messages', 'sent mail', 'envoyes', 'elements envoyes', 'messages envoyes'],
        'drafts' => ['drafts', 'draft', 'brouillons', 'brouillon'],
        'spam' => ['junk', 'spam', 'junk e-mail', 'indesirables', 'courrier indesirable', 'pourriel'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'corbeille', 'elements supprimes'],
        'archive' => ['archive', 'archives'],
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $folders = null;

    /** @var list<array<string, mixed>>|null Les dossiers sans compteurs. */
    private ?array $paths = null;

    public function __construct(
        private readonly MailServer $server,
        private readonly WebmailBox $mailbox,
    ) {}

    public function server(): MailServer
    {
        return $this->server;
    }

    public function address(): string
    {
        return $this->mailbox->address;
    }

    /** La boîte ouverte : son adresse, son titulaire, son site. */
    public function box(): WebmailBox
    {
        return $this->mailbox;
    }

    /* ------------------------------------------------------------------ */
    /* Dossiers */
    /* ------------------------------------------------------------------ */

    /**
     * Les dossiers de la boîte, dans l'ordre de la barre latérale : les six dossiers
     * spéciaux (même absents du serveur), Favoris, puis les dossiers personnels.
     *
     * @return list<array{key: string, path: ?string, name: string, role: ?string, icon: string, total: int, unseen: int, custom: bool}>
     */
    public function folders(): array
    {
        return $this->folders ??= $this->organize($this->server->folders());
    }

    /**
     * Les dossiers sans leurs compteurs, pour trouver un chemin : l'arborescence
     * seule, sans aller-retour vers le serveur quand elle est déjà connue.
     *
     * @return list<array<string, mixed>>
     */
    private function paths(): array
    {
        return $this->folders ?? ($this->paths ??= $this->organize($this->server->folders(false)));
    }

    /**
     * @param  list<array<string, mixed>>  $server
     * @return list<array{key: string, path: ?string, name: string, role: ?string, icon: string, total: int, unseen: int, custom: bool}>
     */
    private function organize(array $server): array
    {
        $byRole = [];
        $custom = [];

        foreach ($server as $folder) {
            if (collect($folder['attributes'])->contains(fn ($attribute) => in_array(strtolower($attribute), ['\\noselect', '\\nonexistent'], true))) {
                continue;
            }

            $role = $this->roleOf($folder);

            if ($role !== null && ! isset($byRole[$role])) {
                $byRole[$role] = $folder;
            } else {
                $custom[] = $folder;
            }
        }

        $folders = [];
        foreach (self::ROLES as $role => $meta) {
            $found = $byRole[$role] ?? null;
            $folders[] = [
                'key' => $meta['key'],
                'path' => $found['path'] ?? null,
                'name' => $meta['name'],
                'role' => $role,
                'icon' => $meta['icon'],
                'total' => (int) ($found['total'] ?? 0),
                'unseen' => (int) ($found['unseen'] ?? 0),
                'custom' => false,
            ];

            if ($role === 'drafts') {
                $folders[] = ['key' => self::FAVORITES, 'path' => null, 'name' => 'Favoris', 'role' => 'favorites', 'icon' => 'favorites', 'total' => 0, 'unseen' => 0, 'custom' => false];
            }
        }

        usort($custom, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        foreach ($custom as $folder) {
            $folders[] = [
                'key' => 'd-'.rtrim(strtr(base64_encode($folder['path']), '+/', '-_'), '='),
                'path' => $folder['path'],
                'name' => $folder['name'],
                'role' => null,
                'icon' => 'folder',
                'total' => (int) $folder['total'],
                'unseen' => (int) $folder['unseen'],
                'custom' => true,
            ];
        }

        return $folders;
    }

    /** @return array<string, mixed>|null */
    public function folder(string $key): ?array
    {
        return collect($this->paths())->firstWhere('key', $key);
    }

    /** Le chemin d'un dossier spécial, créé la première fois qu'il sert. */
    public function pathForRole(string $role): string
    {
        $folder = collect($this->paths())->firstWhere('role', $role);

        if ($folder !== null && $folder['path'] !== null) {
            return $folder['path'];
        }

        if ($role === 'inbox') {
            return 'INBOX';
        }

        $path = $this->prefix().self::ROLES[$role]['create'];
        $this->server->createFolder($path);
        $this->folders = $this->paths = null;

        return $path;
    }

    /** « INBOX. » quand les dossiers de la boîte vivent sous la réception (cPanel), sinon rien. */
    private function prefix(): string
    {
        foreach ($this->server->folders(false) as $folder) {
            $delimiter = $folder['delimiter'] ?: '/';

            if (str_starts_with(strtoupper($folder['path']), 'INBOX'.$delimiter)) {
                return 'INBOX'.$delimiter;
            }
        }

        return '';
    }

    /** @param array{path: string, name: string, attributes: list<string>} $folder */
    private function roleOf(array $folder): ?string
    {
        if (strtoupper($folder['path']) === 'INBOX') {
            return 'inbox';
        }

        foreach ($folder['attributes'] as $attribute) {
            if (isset(self::SPECIAL_USE[strtolower($attribute)])) {
                return self::SPECIAL_USE[strtolower($attribute)];
            }
        }

        $name = Str::of($folder['name'])->ascii()->lower()->squish()->toString();

        foreach (self::NAMES as $role => $names) {
            if (in_array($name, $names, true)) {
                return $role;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Liste et lecture */
    /* ------------------------------------------------------------------ */

    /**
     * La page d'un dossier. Chaque message porte la clé de son dossier : les favoris
     * réunissent plusieurs dossiers.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, truncated: bool}
     */
    /**
     * La page va lire la liste de ce dossier : sa sélection et sa recherche partiront
     * avec les compteurs des dossiers, dans le même aller-retour (MailServer::plan).
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     */
    public function expectListing(string $key, array $criteria = []): void
    {
        $folder = $key === self::FAVORITES ? null : $this->folder($key);

        if ($folder !== null && $folder['path'] !== null) {
            $this->server->plan($folder['path'], $criteria);
        }
    }

    /**
     * La page va ouvrir ce message : sa lecture, sa position et son marquage « lu »
     * partiront avec les compteurs des dossiers, dans le même aller-retour. Un
     * brouillon n'est pas marqué lu : il s'ouvre dans la rédaction.
     */
    public function expectMessage(string $key, int $uid): void
    {
        $folder = $key === self::FAVORITES ? null : $this->folder($key);

        if ($folder !== null && $folder['path'] !== null) {
            $this->server->plan($folder['path'], [], $uid, markSeen: $folder['role'] !== 'drafts');
        }
    }

    public function listing(string $key, int $page, array $criteria = []): array
    {
        $perPage = (int) config('rivo.webmail.per_page', 25);
        $page = max(1, $page);

        if ($key === self::FAVORITES) {
            return $this->favorites($page, $perPage, $criteria);
        }

        // Les compteurs du dossier (tous, non lus) viennent du même lot que la liste.
        $folder = collect($this->folders())->firstWhere('key', $key);

        if ($folder === null || $folder['path'] === null) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'truncated' => false, 'counts' => null];
        }

        $result = $this->server->messages($folder['path'], $page, $perPage, $criteria);
        $items = array_map(fn (array $item) => [...$item, 'folder' => $key], $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'truncated' => false,
            // Ce que comptent les filtres « Tous », « Non lus », « Favoris » : le dossier
            // entier, quelle que soit la recherche en cours.
            'counts' => [
                'all' => (int) $folder['total'],
                'unseen' => (int) $folder['unseen'],
                'flagged' => count($this->server->uids($folder['path'], ['flagged' => true])),
            ],
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, truncated: bool} */
    private function favorites(int $page, int $perPage, array $criteria): array
    {
        $items = [];
        $truncated = false;

        foreach (self::FAVORITE_ROLES as $role) {
            $folder = collect($this->paths())->firstWhere('role', $role);

            if ($folder === null || $folder['path'] === null) {
                continue;
            }

            $result = $this->server->messages($folder['path'], 1, self::FAVORITES_PER_FOLDER, [...$criteria, 'flagged' => true]);
            $truncated = $truncated || $result['total'] > self::FAVORITES_PER_FOLDER;

            foreach ($result['items'] as $item) {
                $items[] = [...$item, 'folder' => $folder['key']];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));
        $total = count($items);

        return [
            'items' => array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'truncated' => $truncated,
            'counts' => null,
        ];
    }

    /**
     * Un message prêt à lire : son corps rendu sûr, ses voisins dans le dossier, et
     * marqué lu (sauf un brouillon, qui s'ouvre dans la rédaction).
     *
     * @return array<string, mixed>|null
     */
    public function open(string $key, int $uid, bool $remoteImages = false): ?array
    {
        $folder = $this->folder($key);

        if ($folder === null || $folder['path'] === null || $key === self::FAVORITES) {
            return null;
        }

        // Les compteurs d'abord : ils partent avec la lecture annoncée (expectMessage),
        // et le non-lu retiré ci-dessous se reporte sur eux.
        $this->folders();

        $message = $this->server->message($folder['path'], $uid);

        if ($message === null) {
            return null;
        }

        if (! $message['seen'] && ! $message['draft']) {
            $this->server->flag($folder['path'], [$uid], '\\Seen', true);
            $message['seen'] = true;
            // Un non-lu de moins : le compteur se corrige ici, sans relire tous les dossiers.
            if ($this->folders !== null) {
                $this->folders = array_map(
                    fn (array $item) => $item['key'] === $key ? [...$item, 'unseen' => max(0, $item['unseen'] - 1)] : $item,
                    $this->folders,
                );
            }
        }

        $body = filled($message['html'])
            ? EmailHtmlSanitizer::forDisplay((string) $message['html'], $message['inline_images'] ?? [], $remoteImages)
            : ['html' => EmailHtmlSanitizer::fromText((string) ($message['text'] ?? '')), 'blocked_images' => 0];

        $uids = $this->server->uids($folder['path']);
        $position = array_search($uid, $uids, true);

        return [
            ...collect($message)->except(['html', 'inline_images'])->all(),
            'folder' => $key,
            'body_html' => $body['html'],
            'blocked_images' => $body['blocked_images'],
            'quote_text' => filled($message['text']) ? (string) $message['text'] : EmailHtmlSanitizer::toText((string) ($message['html'] ?? '')),
            'attachments' => array_values(array_filter($message['attachments'], fn (array $attachment) => ! $attachment['inline'])),
            'newer' => $position !== false && $position > 0 ? $uids[$position - 1] : null,
            'older' => $position !== false && isset($uids[$position + 1]) ? $uids[$position + 1] : null,
            'position' => $position !== false ? $position + 1 : null,
            'count' => count($uids),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Actions groupées */
    /* ------------------------------------------------------------------ */

    /**
     * Une action sur des messages, éventuellement de plusieurs dossiers (favoris).
     *
     * @param  list<array{folder: string, uid: int}>  $items
     * @return string La phrase qui le confirme.
     *
     * @throws ValidationException
     */
    public function act(array $items, string $action, ?string $target = null, ?WebmailLabel $label = null): string
    {
        $groups = [];
        foreach ($items as $item) {
            $folder = $this->folder((string) $item['folder']);

            if ($folder === null || $folder['path'] === null) {
                throw ValidationException::withMessages(['items' => 'Un des messages choisis n’est plus dans ce dossier.']);
            }

            $groups[$folder['key']] ??= ['folder' => $folder, 'uids' => []];
            $groups[$folder['key']]['uids'][] = (int) $item['uid'];
        }

        $count = count($items);

        foreach ($groups as $group) {
            $path = $group['folder']['path'];
            $role = $group['folder']['role'];
            $uids = $group['uids'];

            match ($action) {
                'read' => $this->server->flag($path, $uids, '\\Seen', true),
                'unread' => $this->server->flag($path, $uids, '\\Seen', false),
                'star' => $this->server->flag($path, $uids, '\\Flagged', true),
                'unstar' => $this->server->flag($path, $uids, '\\Flagged', false),
                'archive' => $this->moveUnless($path, $uids, $role, 'archive'),
                'spam' => $this->moveUnless($path, $uids, $role, 'spam'),
                'inbox' => $this->moveUnless($path, $uids, $role, 'inbox'),
                'trash' => $role === 'trash'
                    ? throw ValidationException::withMessages(['action' => 'Ces messages sont déjà dans la corbeille : « Supprimer définitivement » les efface.'])
                    : $this->server->move($path, $uids, $this->pathForRole('trash')),
                'delete' => in_array($role, ['trash', 'spam'], true)
                    ? $this->server->delete($path, $uids)
                    : throw ValidationException::withMessages(['action' => 'Seuls les messages de la corbeille ou des indésirables se suppriment définitivement.']),
                'move' => $this->moveTo($path, $uids, (string) $target),
                'label' => $this->server->flag($path, $uids, (string) $label?->keyword, true),
                'unlabel' => $this->server->flag($path, $uids, (string) $label?->keyword, false),
                default => throw ValidationException::withMessages(['action' => 'Action inconnue.']),
            };
        }

        $this->folders = null;
        $plural = $count > 1 ? 's' : '';

        return match ($action) {
            'read' => "{$count} message{$plural} marqué{$plural} comme lu{$plural}.",
            'unread' => "{$count} message{$plural} marqué{$plural} comme non lu{$plural}.",
            'star' => "{$count} message{$plural} ajouté{$plural} aux favoris.",
            'unstar' => "{$count} message{$plural} retiré{$plural} des favoris.",
            'archive' => "{$count} message{$plural} archivé{$plural}.",
            'spam' => "{$count} message{$plural} déplacé{$plural} dans les indésirables.",
            'inbox' => "{$count} message{$plural} remis dans la boîte de réception.",
            'trash' => "{$count} message{$plural} mis à la corbeille.",
            'delete' => "{$count} message{$plural} supprimé{$plural} définitivement.",
            'move' => "{$count} message{$plural} déplacé{$plural}.",
            'label' => "Libellé « {$label?->name} » ajouté.",
            'unlabel' => "Libellé « {$label?->name} » retiré.",
        };
    }

    /** @param list<int> $uids */
    private function moveUnless(string $path, array $uids, ?string $currentRole, string $role): void
    {
        if ($currentRole !== $role) {
            $this->server->move($path, $uids, $this->pathForRole($role));
        }
    }

    /** @param list<int> $uids */
    private function moveTo(string $path, array $uids, string $targetKey): void
    {
        $target = $this->folder($targetKey);

        if ($target === null || $targetKey === self::FAVORITES) {
            throw ValidationException::withMessages(['target' => 'Choisissez un dossier de destination.']);
        }

        $targetPath = $target['path'] ?? $this->pathForRole((string) $target['role']);

        if ($targetPath !== $path) {
            $this->server->move($path, $uids, $targetPath);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Envoi et brouillons */
    /* ------------------------------------------------------------------ */

    /**
     * Envoie, garde une copie dans Envoyés, marque l'original « répondu » et retire
     * le brouillon qui a servi. Seul l'envoi peut échouer sans rien changer : la
     * copie et les marques suivantes ne font jamais échouer un message déjà parti.
     *
     * @param  array{folder: string, uid: int}|null  $repliedTo
     * @param  array{folder: string, uid: int}|null  $draft
     */
    public function send(Email $email, ?array $repliedTo = null, ?array $draft = null): void
    {
        // L'identifiant et la date sont fixés avant l'envoi : sinon la copie gardée dans
        // Envoyés en recevait d'autres que le message parti, et une réponse ne se
        // rattachait plus au bon fil.
        if (! $email->getHeaders()->has('Message-ID')) {
            $email->getHeaders()->addIdHeader('Message-ID', $email->generateMessageId());
        }
        if (! $email->getHeaders()->has('Date')) {
            $email->date(new \DateTimeImmutable);
        }

        $this->server->send($email);

        try {
            $this->server->append($this->pathForRole('sent'), self::withBcc($email), ['\\Seen']);
        } catch (WebmailUnavailable) {
            // Le message est parti ; sa copie dans Envoyés manquera seulement.
        }

        if ($repliedTo !== null && ($folder = $this->folder($repliedTo['folder'])) !== null && $folder['path'] !== null) {
            try {
                $this->server->flag($folder['path'], [(int) $repliedTo['uid']], '\\Answered', true);
            } catch (WebmailUnavailable) {
            }
        }

        $this->discardDraft($draft);
        $this->folders = $this->paths = null;
    }

    /** @param array{folder: string, uid: int}|null $previous Le brouillon qu'il remplace. */
    public function saveDraft(Email $email, ?array $previous = null): void
    {
        $this->server->append($this->pathForRole('drafts'), self::withBcc($email), ['\\Draft', '\\Seen']);
        $this->discardDraft($previous);
        $this->folders = $this->paths = null;
    }

    /**
     * Le message tel qu'on le garde (Envoyés, Brouillons) : avec ses destinataires en
     * copie cachée, que Symfony retire du message qui part — l'expéditeur, lui, doit
     * savoir à qui il a écrit.
     */
    public static function withBcc(Email $email): string
    {
        $raw = $email->toString();
        $bcc = array_map(fn ($address) => $address->toString(), $email->getBcc());

        return $bcc === [] ? $raw : 'Bcc: '.implode(', ', $bcc)."\r\n".$raw;
    }

    /** @param array{folder: string, uid: int}|null $draft */
    private function discardDraft(?array $draft): void
    {
        if ($draft === null) {
            return;
        }

        $folder = $this->folder($draft['folder']);

        if ($folder !== null && $folder['role'] === 'drafts' && $folder['path'] !== null) {
            try {
                $this->server->delete($folder['path'], [(int) $draft['uid']]);
            } catch (WebmailUnavailable) {
            }
        }
    }

    /**
     * Les pièces jointes d'un message, pour les transférer.
     *
     * @return list<array{name: string, type: string, content: string}>
     */
    public function attachmentsOf(string $key, int $uid): array
    {
        $folder = $this->folder($key);

        if ($folder === null || $folder['path'] === null) {
            return [];
        }

        $message = $this->server->message($folder['path'], $uid);
        $files = [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            if ($attachment['inline']) {
                continue;
            }

            $file = $this->server->attachment($folder['path'], $uid, (string) $attachment['part']);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array{name: string, type: string, content: string}|null */
    public function attachment(string $key, int $uid, string $part): ?array
    {
        $folder = $this->folder($key);

        return $folder !== null && $folder['path'] !== null ? $this->server->attachment($folder['path'], $uid, $part) : null;
    }

    /** @return array<string, mixed>|null Le message tel quel, pour reprendre un brouillon ou répondre. */
    public function source(string $key, int $uid): ?array
    {
        $folder = $this->folder($key);

        return $folder !== null && $folder['path'] !== null ? $this->server->message($folder['path'], $uid) : null;
    }
}
