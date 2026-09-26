<?php

namespace App\Services\Webmail;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * ADR-195 — la vraie boîte chez l'hébergeur : IMAP pour lire, SMTP pour envoyer
 * (webklex/php-imap, sans l'extension imap de PHP, absente de PHP 8.4).
 *
 * Chaque aller-retour compte : le serveur est à ~260 ms (Madagascar ↔ o2switch).
 * D'où : aucun NOOP de contrôle (LeanImapClient), les commandes indépendantes
 * envoyées ensemble (pipelining IMAP, un seul aller-retour), un seul FETCH par liste
 * ou par message, l'arborescence des dossiers, les capacités du serveur et l'espace
 * utilisé gardés quelques minutes, et une déconnexion qui n'attend rien.
 *
 * Le mot de passe n'est jamais écrit dans une erreur ni dans un journal : les
 * exceptions de la bibliothèque sont remplacées par une phrase, sans la pile
 * d'appels qui pourrait porter les arguments de connexion.
 */
final class ImapMailServer implements MailServer
{
    /** Une image incorporée au-delà de cette taille n'est pas affichée dans le corps. */
    private const INLINE_IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    private const UNAVAILABLE = 'Le serveur de messagerie ne répond pas. Réessayez dans un instant.';

    /** L'arborescence des dossiers (noms seulement) change rarement : gardée deux minutes. */
    private const STRUCTURE_SECONDS = 120;

    /** L'espace utilisé : gardé dix minutes. */
    private const QUOTA_SECONDS = 600;

    private LeanImapClient $client;

    /** Le dossier sélectionné sur cette connexion : on ne le resélectionne pas. */
    private ?string $selected = null;

    /** @var list<string>|null */
    private ?array $capabilities = null;

    /** Ce que un FETCH de message demande : drapeaux, en-têtes et corps, sans marquer lu. */
    private const MESSAGE_ITEMS = ['UID', 'FLAGS', 'RFC822.HEADER', 'BODY.PEEK[TEXT]'];

    /** @var array{folder: string, criteria: array<string, mixed>, uid: ?int, markSeen: bool}|null La lecture annoncée (plan). */
    private ?array $plan = null;

    /** @var array<string, list<int>> Les recherches déjà reçues dans le lot des compteurs, sur cette connexion. */
    private array $searched = [];

    /** @var array<string, array<string, mixed>> Les messages déjà reçus dans ce lot, par « dossier:uid ». */
    private array $fetched = [];

    /** @var array<string, true> Les messages déjà marqués lus dans ce lot, par « dossier:uid ». */
    private array $markedSeen = [];

    private function __construct(
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $password,
    ) {}

    /**
     * @throws WebmailAuthenticationFailed
     * @throws WebmailUnavailable
     */
    public static function connect(string $address, #[\SensitiveParameter] string $password): self
    {
        $server = new self($address, $password);
        $server->open();

        return $server;
    }

    private function open(): void
    {
        $config = config('rivo.webmail');

        if (blank($config['imap']['host'] ?? null)) {
            throw WebmailUnavailable::because('Le serveur de messagerie n’est pas configuré sur ce site (RIVO_WEBMAIL_IMAP_HOST).');
        }

        $manager = new ClientManager([
            'options' => [
                'fetch' => IMAP::FT_PEEK,
                'sequence' => IMAP::ST_UID,
                'fetch_body' => true,
                'fetch_flags' => true,
                'soft_fail' => true,
                'rfc822' => true,
                'debug' => false,
                'uid_cache' => true,
                'fetch_order' => 'desc',
            ],
            // Tous les drapeaux, mots-clés compris : les libellés en sont.
            'flags' => null,
            // Sans l'extension imap de PHP, le décodeur « utf-8 » de la bibliothèque
            // laisse les en-têtes encodés tels quels (« =?utf-8?Q?R=C3=A9sultats?= ») :
            // iconv les décode.
            'decoding' => ['options' => ['header' => 'iconv', 'message' => 'utf-8', 'attachment' => 'utf-8']],
        ]);

        // Le client de la bibliothèque, sans ses NOOP de contrôle (LeanImapClient).
        $this->client = new LeanImapClient($manager->make([
            'host' => $config['imap']['host'],
            'port' => (int) $config['imap']['port'],
            'protocol' => 'imap',
            'encryption' => match ($config['imap']['encryption'] ?? 'ssl') {
                'ssl' => 'ssl',
                'tls', 'starttls' => 'tls',
                default => false,
            },
            'validate_cert' => (bool) ($config['imap']['validate_cert'] ?? true),
            'username' => $this->address,
            'password' => $this->password,
            'authentication' => null,
            'timeout' => (int) ($config['timeout'] ?? 20),
        ])->getConfig());

        try {
            $this->client->connect();
        } catch (Throwable $exception) {
            if (self::isRefusal($exception)) {
                $this->log('connexion refusée', $exception);

                throw WebmailAuthenticationFailed::refused();
            }

            $this->log('connexion impossible', $exception);

            throw WebmailUnavailable::because(self::UNAVAILABLE);
        }
    }

    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false): void
    {
        $this->plan = ['folder' => $folder, 'criteria' => $criteria, 'uid' => $uid, 'markSeen' => $markSeen];
    }

    public function folders(bool $withCounts = true): array
    {
        return $this->guard('lire les dossiers', function () use ($withCounts): array {
            $structure = $this->structure();

            if (! $withCounts) {
                return array_map(fn (array $folder) => [...$folder, 'total' => 0, 'unseen' => 0], $structure);
            }
            $selectable = array_values(array_filter($structure, fn (array $folder) => array_intersect(
                array_map('strtolower', $folder['attributes']),
                ['\\noselect', '\\nonexistent'],
            ) === []));

            // Les compteurs de tous les dossiers en un seul aller-retour — et, dans le même
            // envoi, ce que la page lira ensuite (plan) : sélection, recherche, message.
            $status = array_map(
                fn (array $folder) => ['STATUS', [$this->quote($folder['path']), '(MESSAGES UNSEEN)']],
                $selectable,
            );
            [$planned, $readers] = $this->plannedCommands();
            $responses = $this->pipeline([...$status, ...$planned], tolerant: true);

            foreach ($readers as $offset => $reader) {
                $reader?->__invoke($responses[count($status) + $offset] ?? null);
            }

            $counts = [];
            foreach (array_slice($responses, 0, count($status)) as $lines) {
                foreach ($lines ?? [] as $line) {
                    if (($line[0] ?? null) === 'STATUS' && isset($line[1], $line[2]) && is_array($line[2])) {
                        $counts[(string) $line[1]] = self::pairs($line[2]);
                    }
                }
            }

            return array_map(fn (array $folder) => [
                ...$folder,
                'total' => (int) ($counts[$folder['path']]['messages'] ?? 0),
                'unseen' => (int) ($counts[$folder['path']]['unseen'] ?? 0),
            ], $structure);
        });
    }

    public function uids(string $folder, array $criteria = []): array
    {
        return $this->guard('chercher les messages', function () use ($folder, $criteria): array {
            return $this->search($folder, $criteria);
        });
    }

    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array
    {
        return $this->guard('lire les messages', function () use ($folder, $page, $perPage, $criteria): array {
            $ids = $this->search($folder, $criteria);
            $total = count($ids);
            $pageIds = array_slice($ids, (max(1, $page) - 1) * $perPage, $perPage);

            if ($pageIds === []) {
                return ['total' => $total, 'items' => []];
            }

            // Seuls les messages de la page, sans leur corps : drapeaux et en-têtes en une commande.
            $data = $this->protocol()->fetch(['UID', 'FLAGS', 'RFC822.HEADER'], $pageIds, null, IMAP::ST_UID)->validatedData();

            $items = [];
            foreach ((array) $data as $uid => $item) {
                $flags = self::flagsOf($item);
                $items[] = $this->summary($this->build((int) $uid, (string) ($item['RFC822.HEADER'] ?? ''), '', $flags), $flags);
            }

            usort($items, fn (array $a, array $b) => $b['uid'] <=> $a['uid']);

            return ['total' => $total, 'items' => $items];
        });
    }

    public function message(string $folder, int $uid): ?array
    {
        return $this->guard('lire le message', function () use ($folder, $uid): ?array {
            [$message, $flags] = $this->fetch($folder, $uid) ?? [null, []];

            if ($message === null) {
                return null;
            }

            $attachments = [];
            $inline = [];

            foreach ($message->getAttachments() as $attachment) {
                /** @var Attachment $attachment */
                // Sans Content-ID, la bibliothèque donne pour identifiant une empreinte :
                // ce n'est pas une image incorporée, et elle reste une pièce jointe.
                $cid = (string) $attachment->id !== (string) $attachment->hash ? trim((string) $attachment->id, '<> ') : '';
                $type = strtolower((string) ($attachment->content_type ?: $attachment->getMimeType() ?: 'application/octet-stream'));
                $isInline = $cid !== '' && str_starts_with($type, 'image/') && strtolower((string) $attachment->disposition) !== 'attachment';

                if ($isInline && strlen((string) $attachment->content) <= self::INLINE_IMAGE_MAX_BYTES) {
                    $inline[$cid] = 'data:'.$type.';base64,'.base64_encode((string) $attachment->content);
                }

                $attachments[] = [
                    'part' => (string) $attachment->part_number,
                    'name' => (string) ($attachment->name ?: $attachment->filename ?: 'piece-jointe'),
                    'type' => $type,
                    'size' => (int) ($attachment->size ?? strlen((string) $attachment->content)),
                    'cid' => $cid !== '' ? $cid : null,
                    'inline' => $isInline,
                ];
            }

            return [
                ...$this->summary($message, $flags),
                'cc' => self::addresses($message->getCc()->all()),
                'bcc' => self::addresses($message->getBcc()->all()),
                'reply_to' => self::addresses($message->getReplyTo()->all()),
                'html' => $message->hasHTMLBody() ? $message->getHTMLBody() : null,
                'text' => $message->hasTextBody() ? $message->getTextBody() : null,
                'message_id' => self::messageId((string) $message->getMessageId()),
                'in_reply_to' => self::messageId((string) $message->getInReplyTo()),
                'references' => trim((string) $message->getReferences()) ?: null,
                'attachments' => $attachments,
                'inline_images' => $inline,
                'has_attachments' => collect($attachments)->contains(fn (array $item) => ! $item['inline']),
            ];
        });
    }

    public function attachment(string $folder, int $uid, string $part): ?array
    {
        return $this->guard('lire la pièce jointe', function () use ($folder, $uid, $part): ?array {
            [$message] = $this->fetch($folder, $uid) ?? [null];

            foreach ($message?->getAttachments() ?? [] as $attachment) {
                if ((string) $attachment->part_number === $part) {
                    return [
                        'name' => (string) ($attachment->name ?: $attachment->filename ?: 'piece-jointe'),
                        'type' => strtolower((string) ($attachment->content_type ?: 'application/octet-stream')),
                        'content' => (string) $attachment->content,
                    ];
                }
            }

            return null;
        });
    }

    public function raw(string $folder, int $uid): ?string
    {
        return $this->guard('lire le message', function () use ($folder, $uid): ?string {
            $data = $this->selectedThen($folder, fn () => $this->protocol()->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData());
            $item = $data[$uid] ?? null;

            return is_array($item) && isset($item['BODY[]']) ? (string) $item['BODY[]'] : null;
        });
    }

    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        if ($uids === []) {
            return;
        }

        // Déjà marqué lu dans le lot de l'ouverture (plan) : rien à redemander au serveur.
        if ($flag === '\\Seen' && $on && array_filter($uids, fn ($uid) => ! isset($this->markedSeen[$folder.':'.$uid])) === []) {
            return;
        }

        $this->forgetReads();

        $this->guard('modifier les messages', function () use ($folder, $uids, $flag, $on): void {
            $this->pipeline([
                ...$this->selectCommand($folder),
                ['UID STORE', [self::set($uids), ($on ? '+' : '-').'FLAGS.SILENT', '('.$flag.')']],
            ]);
        });
    }

    public function move(string $folder, array $uids, string $target): void
    {
        if ($uids === []) {
            return;
        }

        $this->forgetReads();

        $this->guard('déplacer les messages', function () use ($folder, $uids, $target): void {
            $set = self::set($uids);

            $this->pipeline($this->capable('MOVE')
                ? [...$this->selectCommand($folder), ['UID MOVE', [$set, $this->quote($target)]]]
                : [
                    ...$this->selectCommand($folder),
                    ['UID COPY', [$set, $this->quote($target)]],
                    ['UID STORE', [$set, '+FLAGS.SILENT', '(\\Deleted)']],
                    ['EXPUNGE', []],
                ]);
        });
    }

    public function delete(string $folder, array $uids): void
    {
        if ($uids === []) {
            return;
        }

        $this->forgetReads();

        $this->guard('supprimer les messages', function () use ($folder, $uids): void {
            $this->pipeline([
                ...$this->selectCommand($folder),
                ['UID STORE', [self::set($uids), '+FLAGS.SILENT', '(\\Deleted)']],
                ['EXPUNGE', []],
            ]);
        });
    }

    public function append(string $folder, string $raw, array $flags = []): void
    {
        $this->forgetReads();

        $this->guard('enregistrer le message', function () use ($folder, $raw, $flags): void {
            if (! $this->capable('LITERAL+')) {
                $this->protocol()->appendMessage($folder, $raw, $flags === [] ? null : $flags);

                return;
            }

            // LITERAL+ : le message part avec la commande, sans attendre l'invite du serveur.
            $tokens = [$this->quote($folder)];
            if ($flags !== []) {
                $tokens[] = '('.implode(' ', $flags).')';
            }
            $tokens[] = '{'.strlen($raw)."+}\r\n".$raw;

            $this->protocol()->requestAndResponse('APPEND', $tokens, true);
        });
    }

    public function createFolder(string $path): void
    {
        $this->guard('créer le dossier', function () use ($path): void {
            $this->protocol()->createFolder($path);
            Cache::forget($this->cacheKey('structure'));

            try {
                $this->protocol()->subscribeFolder($path);
            } catch (Throwable) {
                // S'abonner est un confort des autres logiciels de messagerie.
            }
        });
    }

    public function quota(): ?array
    {
        return Cache::remember($this->cacheKey('quota'), self::QUOTA_SECONDS, fn () => $this->readQuota());
    }

    /** @return array{used: int, limit: int}|null */
    private function readQuota(): ?array
    {
        try {
            $data = $this->protocol()->getQuotaRoot('INBOX')->data();
        } catch (Throwable) {
            return null;
        }

        $tokens = [];
        array_walk_recursive($data, function ($value) use (&$tokens): void {
            $tokens[] = $value;
        });

        foreach ($tokens as $index => $token) {
            if (is_string($token) && strtoupper($token) === 'STORAGE' && isset($tokens[$index + 1], $tokens[$index + 2])
                && is_numeric($tokens[$index + 1]) && is_numeric($tokens[$index + 2])) {
                // STORAGE s'exprime en kilo-octets.
                return ['used' => (int) $tokens[$index + 1] * 1024, 'limit' => (int) $tokens[$index + 2] * 1024];
            }
        }

        return null;
    }

    public function send(Email $email): void
    {
        $smtp = config('rivo.webmail.smtp');

        if (blank($smtp['host'] ?? null)) {
            throw WebmailUnavailable::because('L’envoi n’est pas configuré sur ce site (RIVO_WEBMAIL_SMTP_HOST).');
        }

        $port = (int) ($smtp['port'] ?? 465);
        // 465 : TLS dès la connexion ; sinon STARTTLS, que Symfony active quand le serveur le propose.
        $transport = new EsmtpTransport((string) $smtp['host'], $port, ($smtp['encryption'] ?? 'ssl') === 'ssl' || $port === 465);
        // PLAIN d'abord : un seul aller-retour, contre trois pour LOGIN.
        $transport->setAuthenticators([new PlainAuthenticator, new LoginAuthenticator]);
        $transport->setUsername($this->address);
        $transport->setPassword($this->password);
        $transport->getStream()->setTimeout((float) (config('rivo.webmail.timeout') ?? 20));

        if (($smtp['encryption'] ?? 'ssl') === 'none') {
            $transport->setAutoTls(false);
        }

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->closeSmtp($transport);
            $this->log('envoi refusé', $exception);

            throw WebmailUnavailable::because(str_contains(strtolower($exception->getMessage()), 'auth')
                ? 'Le serveur d’envoi refuse ce mot de passe.'
                : 'Le message n’a pas pu être envoyé : le serveur d’envoi ne répond pas. Il est gardé dans le formulaire.');
        }

        // Le message est parti : le « QUIT » attend la fin de la requête, pas l'écran.
        app()->terminating(fn () => $this->closeSmtp($transport));
    }

    private function closeSmtp(EsmtpTransport $transport): void
    {
        try {
            $transport->stop();
        } catch (Throwable) {
        }
    }

    public function disconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (Throwable) {
        }
    }

    /* ------------------------------------------------------------------ */

    private function protocol(): ImapProtocol
    {
        $protocol = $this->client->connection;

        if (! $protocol instanceof ImapProtocol) {
            throw WebmailUnavailable::because(self::UNAVAILABLE);
        }

        return $protocol;
    }

    /**
     * Envoie des commandes indépendantes d'un seul coup, puis lit leurs réponses
     * dans l'ordre : un aller-retour pour toutes (pipelining IMAP, RFC 3501 §5.5).
     * Toutes les réponses sont lues, même après un refus : sinon elles resteraient
     * dans le flux et fausseraient la commande suivante.
     *
     * @param  list<array{0: string, 1: array<int, mixed>, 2?: string}>  $commands  le 3e élément : le dossier d'un SELECT
     * @return list<array<int, mixed>|null> les lignes non étiquetées de chaque commande (null si refusée)
     */
    private function pipeline(array $commands, bool $tolerant = false): array
    {
        $protocol = $this->protocol();
        $pending = [];

        foreach ($commands as $command) {
            $tag = null;
            $pending[] = [$protocol->sendRequest($command[0], $command[1], $tag), $tag, $command[2] ?? null];
        }

        $results = [];
        $failure = null;

        foreach ($pending as [$response, $tag, $selecting]) {
            try {
                $results[] = $protocol->readResponse($response, $tag);

                if ($selecting !== null) {
                    $this->selected = $selecting;
                }
            } catch (Throwable $exception) {
                $results[] = null;
                $failure ??= $exception;

                if ($selecting !== null) {
                    $this->selected = null;
                }
            }
        }

        if ($failure !== null && ! $tolerant) {
            throw $failure;
        }

        return $results;
    }

    /**
     * Les commandes de la lecture annoncée (plan), à envoyer avec les compteurs des
     * dossiers : la sélection, la recherche de la liste, et pour un message sa lecture
     * puis son marquage « lu ». Chacune a son lecteur, qui range sa réponse pour la
     * méthode qui la demandera ensuite (search, fetch, flag). Une réponse refusée
     * n'est pas rangée : la méthode la redemandera, et dira l'erreur comme avant.
     *
     * Le marquage part après la lecture : les drapeaux reçus sont ceux d'avant, et la
     * messagerie sait si le message était non lu.
     *
     * @return array{0: list<array<int, mixed>>, 1: list<(callable(?array<int, mixed>): void)|null>}
     */
    private function plannedCommands(): array
    {
        $plan = $this->plan;
        $this->plan = null;

        if ($plan === null) {
            return [[], []];
        }

        $folder = $plan['folder'];
        $commands = [];
        $readers = [];

        foreach ($this->selectCommand($folder) as $select) {
            $commands[] = $select;
            $readers[] = null;
        }

        if (($search = $this->searchCommand($plan['criteria'])) !== null) {
            $key = $this->searchKey($folder, $plan['criteria']);
            $commands[] = $search;
            $readers[] = function (?array $lines) use ($key): void {
                if ($lines !== null) {
                    $this->searched[$key] = self::searchIds($lines);
                }
            };
        }

        // Une liste affiche combien de favoris compte son dossier : cette recherche-là
        // part dans le même envoi, sans aller-retour de plus.
        $flagged = ['flagged' => true];
        if ($plan['uid'] === null && $this->searchKey($folder, $flagged) !== $this->searchKey($folder, $plan['criteria'])) {
            $flaggedKey = $this->searchKey($folder, $flagged);
            $commands[] = $this->searchCommand($flagged);
            $readers[] = function (?array $lines) use ($flaggedKey): void {
                if ($lines !== null) {
                    $this->searched[$flaggedKey] = self::searchIds($lines);
                }
            };
        }

        if (($uid = $plan['uid']) !== null) {
            $commands[] = ['UID FETCH', [$uid.':'.$uid, '('.implode(' ', self::MESSAGE_ITEMS).')']];
            $readers[] = function (?array $lines) use ($folder, $uid): void {
                if ($lines !== null && ($item = self::fetchItems($lines)[$uid] ?? null) !== null) {
                    $this->fetched[$folder.':'.$uid] = $item;
                }
            };

            if ($plan['markSeen']) {
                $commands[] = ['UID STORE', [$uid.':'.$uid, '+FLAGS.SILENT', '(\\Seen)']];
                $readers[] = function (?array $lines) use ($folder, $uid): void {
                    if ($lines !== null) {
                        $this->markedSeen[$folder.':'.$uid] = true;
                    }
                };
            }
        }

        return [$commands, $readers];
    }

    /** Ce qui a été lu d'avance ne vaut plus après une modification de la boîte. */
    private function forgetReads(): void
    {
        $this->searched = [];
        $this->fetched = [];
    }

    /**
     * La commande SELECT d'un dossier, à glisser devant les suivantes — rien s'il
     * est déjà sélectionné sur cette connexion.
     *
     * @return list<array{0: string, 1: array<int, mixed>, 2: string}>
     */
    private function selectCommand(string $folder): array
    {
        if ($this->selected === $folder) {
            return [];
        }

        $this->client->setActiveFolder($folder);

        return [['SELECT', [$this->quote($folder)], $folder]];
    }

    /**
     * Une commande sur un dossier, envoyée avec sa sélection dans le même aller-retour :
     * la lecture de la bibliothèque (FETCH) passe sur les lignes du SELECT, qui ne sont
     * pas les siennes. Un SELECT refusé fait échouer la commande qui suit.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function selectedThen(string $folder, callable $run): mixed
    {
        if ($this->selected === $folder) {
            return $run();
        }

        $this->client->setActiveFolder($folder);
        $this->selected = null;
        $tag = null;
        $this->protocol()->sendRequest('SELECT', [$this->quote($folder)], $tag);

        $result = $run();
        $this->selected = $folder;

        return $result;
    }

    private function select(string $folder): void
    {
        if ($commands = $this->selectCommand($folder)) {
            $this->pipeline($commands);
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * L'arborescence des dossiers — chemins, noms, attributs —, gardée deux minutes :
     * elle change rarement, et chaque page en a besoin. Un dossier créé par RIVO
     * l'efface aussitôt.
     *
     * @return list<array{path: string, name: string, delimiter: string, attributes: list<string>}>
     */
    private function structure(): array
    {
        return Cache::remember($this->cacheKey('structure'), self::STRUCTURE_SECONDS, function (): array {
            $folders = [];

            foreach ($this->protocol()->folders('', '*')->validatedData() as $path => $info) {
                $path = (string) $path;
                $delimiter = (string) ($info['delimiter'] ?? '/');
                $segments = $delimiter !== '' ? explode($delimiter, $path) : [$path];

                $folders[] = [
                    'path' => $path,
                    'name' => self::decodeName((string) end($segments)),
                    'delimiter' => $delimiter,
                    'attributes' => array_map('strval', (array) ($info['flags'] ?? [])),
                ];
            }

            return $folders;
        });
    }

    /**
     * Ce que le serveur sait faire (MOVE, LITERAL+…), lu une fois par jour et par
     * serveur. Sans réponse, on fait comme s'il ne savait rien de plus que la norme.
     */
    private function capable(string $capability): bool
    {
        if ($this->capabilities === null) {
            $host = (string) config('rivo.webmail.imap.host');
            $this->capabilities = Cache::remember('webmail.imap.capabilities.'.sha1($host), 86400, function (): array {
                try {
                    foreach ($this->protocol()->requestAndResponse('CAPABILITY')->validatedData() as $line) {
                        if (($line[0] ?? null) === 'CAPABILITY') {
                            return array_map(fn ($token) => strtoupper((string) $token), array_slice($line, 1));
                        }
                    }
                } catch (Throwable) {
                }

                return [];
            });
        }

        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    /** Une clé de cache propre à cette boîte : son adresse, jamais son mot de passe. */
    private function cacheKey(string $what): string
    {
        return 'webmail.imap.'.$what.'.'.sha1(config('rivo.webmail.imap.host').'|'.mb_strtolower($this->address));
    }

    /**
     * Les UID d'un dossier qui répondent aux critères, du plus récent au plus ancien.
     *
     * La recherche est écrite à la main : la bibliothèque refuse `CHARSET`, sans
     * lequel un texte accentué (« Échographie ») ne trouve rien. Le texte est
     * nettoyé avant (`searchText`) : ni guillemet ni barre oblique ne peuvent en
     * sortir. Sélection et recherche partent ensemble.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     * @return list<int>
     */
    private function search(string $folder, array $criteria): array
    {
        // Déjà reçue dans le lot des compteurs (plan) : pas d'aller-retour de plus.
        $key = $this->searchKey($folder, $criteria);

        if (isset($this->searched[$key])) {
            return $this->searched[$key];
        }

        if (($search = $this->searchCommand($criteria)) === null) {
            // Un littéral synchronisé attend l'invite du serveur : il ne se glisse pas dans un lot.
            $this->select($folder);
            $responses = $this->pipeline([$this->searchCommand($criteria, synchronized: true)]);
        } else {
            $responses = $this->pipeline([...$this->selectCommand($folder), $search]);
        }

        return self::searchIds(end($responses) ?: []);
    }

    /**
     * La commande UID SEARCH des critères. `null` quand un texte accentué doit partir
     * en littéral synchronisé (serveur sans LITERAL+) et qu'on ne l'accepte pas : il
     * attend l'invite du serveur, et ne se glisse donc pas dans un lot.
     *
     * @param  array{text?: ?string, unseen?: bool, flagged?: bool, keyword?: ?string}  $criteria
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    private function searchCommand(array $criteria, bool $synchronized = false): ?array
    {
        $tokens = [];

        if (($text = self::searchText($criteria['text'] ?? null)) !== null) {
            if (preg_match('/[^\x20-\x7E]/', $text) === 1) {
                // Un texte accentué part en « littéral » IMAP, précédé de son jeu
                // de caractères : la seule forme que la norme garantit pour des octets
                // hors ASCII. Avec LITERAL+, sans attendre l'invite du serveur.
                if ($this->capable('LITERAL+')) {
                    array_push($tokens, 'CHARSET', 'UTF-8', 'TEXT', '{'.strlen($text)."+}\r\n".$text);
                } elseif (! $synchronized) {
                    return null;
                } else {
                    array_push($tokens, 'CHARSET', 'UTF-8', 'TEXT', ['{'.strlen($text).'}', $text]);
                }
            } else {
                array_push($tokens, 'TEXT', '"'.$text.'"');
            }
        }
        if (! empty($criteria['unseen'])) {
            $tokens[] = 'UNSEEN';
        }
        if (! empty($criteria['flagged'])) {
            $tokens[] = 'FLAGGED';
        }
        if (($keyword = self::keyword($criteria['keyword'] ?? null)) !== null) {
            array_push($tokens, 'KEYWORD', $keyword);
        }

        return ['UID SEARCH', $tokens ?: ['ALL']];
    }

    /**
     * Les UID d'une réponse SEARCH, du plus récent au plus ancien.
     *
     * @param  array<int, mixed>  $lines
     * @return list<int>
     */
    private static function searchIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            if (($line[0] ?? null) === 'SEARCH') {
                array_push($ids, ...array_slice($line, 1));
            }
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        rsort($ids);

        return $ids;
    }

    /** Une recherche se reconnaît à son dossier et à ses critères une fois nettoyés. */
    private function searchKey(string $folder, array $criteria): string
    {
        return $folder."\n".json_encode([
            self::searchText($criteria['text'] ?? null),
            ! empty($criteria['unseen']),
            ! empty($criteria['flagged']),
            self::keyword($criteria['keyword'] ?? null),
        ]);
    }

    /**
     * Un message entier — drapeaux, en-têtes et corps — en une commande, sans le
     * marquer lu (BODY.PEEK) : c'est la messagerie qui décide quand il l'est.
     *
     * @return array{0: Message, 1: list<string>}|null
     */
    private function fetch(string $folder, int $uid): ?array
    {
        // Déjà reçu dans le lot des compteurs (plan) : pas d'aller-retour de plus.
        $item = $this->fetched[$folder.':'.$uid] ?? null;

        if ($item === null) {
            try {
                $data = $this->selectedThen($folder, fn () => $this->protocol()->fetch(self::MESSAGE_ITEMS, [$uid], null, IMAP::ST_UID)->validatedData());
            } catch (Throwable) {
                return null;
            }

            $item = $data[$uid] ?? null;
        }

        if (! is_array($item)) {
            return null;
        }

        $flags = self::flagsOf($item);

        return [$this->build($uid, (string) ($item['RFC822.HEADER'] ?? ''), (string) ($item['BODY[TEXT]'] ?? ''), $flags), $flags];
    }

    /**
     * Un message de la bibliothèque, construit sans lui laisser toucher au serveur :
     * elle retirerait le drapeau « lu » d'un message non lu à chaque lecture
     * (Message::peek). Elle le reçoit donc toujours « lu » ; les vrais drapeaux sont
     * lus à côté.
     *
     * @param  list<string>  $flags
     */
    private function build(int $uid, string $header, string $body, array $flags): Message
    {
        $forLibrary = array_map(fn (string $flag) => '\\'.$flag, $flags);
        if (! in_array('Seen', $flags, true)) {
            $forLibrary[] = '\\Seen';
        }

        return Message::make($uid, null, $this->client, $header, $body, $forLibrary, IMAP::FT_PEEK, IMAP::ST_UID);
    }

    /**
     * Les drapeaux d'une réponse FETCH, sans barre oblique : « Seen », « Flagged »,
     * et les mots-clés (libellés) tels quels.
     *
     * @param  array<string, mixed>|mixed  $item
     * @return list<string>
     */
    private static function flagsOf(mixed $item): array
    {
        $flags = is_array($item) ? ($item['FLAGS'] ?? []) : [];

        return array_values(array_filter(array_map(
            fn ($flag) => ltrim((string) $flag, '\\'),
            is_array($flags) ? $flags : [$flags],
        ), fn (string $flag) => $flag !== ''));
    }

    /**
     * Les messages d'une réponse FETCH reçue en lot, par UID : chaque ligne
     * « * n FETCH (UID 45 FLAGS (…) RFC822.HEADER … BODY[TEXT] …) » devient la même
     * table que la lecture de la bibliothèque (clé → valeur).
     *
     * @param  array<int, mixed>  $lines
     * @return array<int, array<string, mixed>>
     */
    private static function fetchItems(array $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            if (($line[1] ?? null) !== 'FETCH' || ! is_array($line[2] ?? null)) {
                continue;
            }

            $tokens = array_values($line[2]);
            $data = [];
            for ($i = 0; $i + 1 < count($tokens); $i += 2) {
                $data[(string) $tokens[$i]] = $tokens[$i + 1];
            }

            if (isset($data['UID']) && is_numeric($data['UID'])) {
                $items[(int) $data['UID']] = $data;
            }
        }

        return $items;
    }

    /** @param list<int|string> $uids */
    private static function set(array $uids): string
    {
        return implode(',', array_map('intval', $uids));
    }

    /**
     * « MESSAGES 12 UNSEEN 3 » → ['messages' => 12, 'unseen' => 3].
     *
     * @param  array<int, mixed>  $tokens
     * @return array<string, int>
     */
    private static function pairs(array $tokens): array
    {
        $result = [];

        for ($i = 0; $i + 1 < count($tokens); $i += 2) {
            $result[strtolower((string) $tokens[$i])] = (int) $tokens[$i + 1];
        }

        return $result;
    }

    /**
     * @param  list<string>  $flags
     * @return array<string, mixed>
     */
    private function summary(Message $message, array $flags): array
    {
        $flags = collect($flags);
        $has = fn (string $name) => $flags->contains(fn (string $flag) => strcasecmp($flag, $name) === 0);
        $from = self::addresses($message->getFrom()->all());
        $contentType = strtolower((string) $message->getHeader()?->get('content_type'));

        return [
            'uid' => (int) $message->getUid(),
            'from' => $from[0] ?? ['name' => '', 'email' => ''],
            'to' => self::addresses($message->getTo()->all()),
            'subject' => trim((string) $message->getSubject()),
            'date' => self::date($message),
            'seen' => $has('Seen'),
            'flagged' => $has('Flagged'),
            'answered' => $has('Answered'),
            'draft' => $has('Draft'),
            'keywords' => $flags->reject(fn (string $flag) => in_array(strtolower($flag), ['seen', 'flagged', 'answered', 'draft', 'deleted', 'recent'], true))->values()->all(),
            // Sans le corps, l'en-tête dit s'il y a des pièces jointes.
            'has_attachments' => str_contains($contentType, 'multipart/mixed'),
        ];
    }

    /** @param array<int, mixed> $addresses @return list<array{name: string, email: string}> */
    private static function addresses(array $addresses): array
    {
        return array_values(array_filter(array_map(fn ($address) => $address instanceof Address
            ? ['name' => trim($address->personal, '" '), 'email' => strtolower($address->mail)]
            : null, $addresses), fn ($address) => $address !== null && $address['email'] !== ''));
    }

    private static function date(Message $message): ?string
    {
        try {
            $date = $message->getDate()->toDate();

            return $date instanceof CarbonInterface ? $date->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function messageId(string $value): ?string
    {
        $value = trim($value, " \t<>");

        return $value !== '' ? $value : null;
    }

    /**
     * Le serveur a-t-il refusé l'identifiant ou le mot de passe ?
     *
     * La bibliothèque ne lève `AuthFailedException` que dans certains cas ; un
     * « NO » du serveur à la connexion lui arrive comme une erreur de serveur
     * (Dovecot : « NO [AUTHENTICATIONFAILED] Authentication failed. »). Le lire
     * comme une panne ferait dire « le serveur ne répond pas » à qui s'est trompé
     * de mot de passe.
     */
    public static function isRefusal(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AuthFailedException) {
                return true;
            }

            if ($current instanceof ImapServerErrorException
                && preg_match('/AUTHENTICATIONFAILED|AUTHORIZATIONFAILED|LOGIN failed|authentication failed|invalid (login|credentials|password)/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Un texte de recherche sans guillemet ni barre oblique : il est placé entre guillemets tel quel. */
    public static function searchText(?string $text): ?string
    {
        $clean = trim(preg_replace('/["\\\\\r\n\t]+/u', ' ', (string) $text) ?? '');

        return $clean !== '' ? mb_substr($clean, 0, 100) : null;
    }

    /** Un mot-clé IMAP : lettres, chiffres et tirets seulement. */
    public static function keyword(?string $keyword): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $keyword) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private static function decodeName(string $name): string
    {
        if (! str_contains($name, '&')) {
            return $name;
        }

        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $name;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guard(string $what, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (WebmailUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($what, $exception);

            if (self::isRefusal($exception)) {
                throw WebmailAuthenticationFailed::refused();
            }

            throw WebmailUnavailable::because("Impossible de {$what} : le serveur de messagerie ne répond pas.");
        }
    }

    /** Une trace sans pile d'appels : ses arguments pourraient porter le mot de passe. */
    private function log(string $what, Throwable $exception): void
    {
        Log::warning('Messagerie : '.$what, [
            'address' => $this->address,
            'error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 300),
        ]);
    }
}
