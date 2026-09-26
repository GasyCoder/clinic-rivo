<?php

namespace Tests\Support\Webmail;

use App\Services\Webmail\MailServer;
use App\Services\Webmail\WebmailUnavailable;
use Symfony\Component\Mime\Email;

/**
 * ADR-194 — une boîte en mémoire, pour les tests : les mêmes réponses que la vraie,
 * sans serveur. Ses dossiers suivent la disposition de cPanel (« INBOX.Sent »…) ;
 * Archives et Indésirables n'existent pas d'avance, pour vérifier qu'ils se créent.
 */
final class FakeMailServer implements MailServer
{
    /** @var array<string, array{delimiter: string, attributes: list<string>, messages: array<int, array<string, mixed>>}> */
    public array $folders = [];

    /** @var list<Email> */
    public array $sent = [];

    public bool $failSend = false;

    public int $disconnects = 0;

    /** @var array{used: int, limit: int}|null */
    public ?array $quota = ['used' => 5 * 1024 * 1024, 'limit' => 1024 * 1024 * 1024];

    private int $nextUid = 1;

    public function __construct()
    {
        foreach (['INBOX' => [], 'INBOX.Sent' => ['\\Sent'], 'INBOX.Drafts' => ['\\Drafts'], 'INBOX.Trash' => ['\\Trash']] as $path => $attributes) {
            $this->folders[$path] = ['delimiter' => '.', 'attributes' => $attributes, 'messages' => []];
        }
    }

    /** @param array<string, mixed> $message */
    public function seed(string $folder, array $message = []): int
    {
        $this->folders[$folder] ??= ['delimiter' => '.', 'attributes' => [], 'messages' => []];
        $uid = $this->nextUid++;

        $this->folders[$folder]['messages'][$uid] = [
            'uid' => $uid,
            'from' => ['name' => 'Expéditeur', 'email' => 'expediteur@exemple.mg'],
            'to' => [['name' => '', 'email' => 'soa.rakoto@cbdc.mg']],
            'cc' => [],
            'bcc' => [],
            'reply_to' => [],
            'subject' => 'Sujet',
            'date' => now()->subMinutes(100 - $uid)->toIso8601String(),
            'flags' => [],
            'html' => null,
            'text' => 'Bonjour.',
            'message_id' => "m{$uid}@exemple.mg",
            'in_reply_to' => null,
            'references' => null,
            'attachments' => [],
            'raw' => null,
            ...$message,
            'uid' => $uid,
        ];

        return $uid;
    }

    /** @return array<string, mixed>|null */
    public function stored(string $folder, int $uid): ?array
    {
        return $this->folders[$folder]['messages'][$uid] ?? null;
    }

    /** @var list<array{folder: string, criteria: array<string, mixed>, uid: ?int, markSeen: bool}> Les lectures annoncées. */
    public array $plans = [];

    /** Une boîte en mémoire n'a pas d'aller-retour à épargner : l'annonce est seulement retenue. */
    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false): void
    {
        $this->plans[] = compact('folder', 'criteria', 'uid', 'markSeen');
    }

    public function folders(bool $withCounts = true): array
    {
        $folders = [];
        foreach ($this->folders as $path => $folder) {
            $segments = explode('.', $path);
            $folders[] = [
                'path' => $path,
                'name' => end($segments),
                'delimiter' => $folder['delimiter'],
                'attributes' => $folder['attributes'],
                'total' => count($folder['messages']),
                'unseen' => count(array_filter($folder['messages'], fn ($message) => ! in_array('\\Seen', $message['flags'], true))),
            ];
        }

        return $folders;
    }

    public function uids(string $folder, array $criteria = []): array
    {
        return array_map(fn ($message) => $message['uid'], $this->filtered($folder, $criteria));
    }

    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array
    {
        $all = $this->filtered($folder, $criteria);

        return [
            'total' => count($all),
            'items' => array_map(fn ($message) => $this->summary($message), array_slice($all, ($page - 1) * $perPage, $perPage)),
        ];
    }

    public function message(string $folder, int $uid): ?array
    {
        $message = $this->stored($folder, $uid);

        if ($message === null) {
            return null;
        }

        $inline = [];
        foreach ($message['attachments'] as $attachment) {
            if ($attachment['inline'] ?? false) {
                $inline[$attachment['cid']] = 'data:'.$attachment['type'].';base64,'.base64_encode($attachment['content']);
            }
        }

        return [
            ...$this->summary($message),
            'cc' => $message['cc'],
            'bcc' => $message['bcc'],
            'reply_to' => $message['reply_to'],
            'html' => $message['html'],
            'text' => $message['text'],
            'message_id' => $message['message_id'],
            'in_reply_to' => $message['in_reply_to'],
            'references' => $message['references'],
            'attachments' => array_map(fn ($attachment) => [
                'part' => $attachment['part'],
                'name' => $attachment['name'],
                'type' => $attachment['type'],
                'size' => strlen($attachment['content']),
                'cid' => $attachment['cid'] ?? null,
                'inline' => $attachment['inline'] ?? false,
            ], $message['attachments']),
            'inline_images' => $inline,
        ];
    }

    public function attachment(string $folder, int $uid, string $part): ?array
    {
        foreach ($this->stored($folder, $uid)['attachments'] ?? [] as $attachment) {
            if ($attachment['part'] === $part) {
                return ['name' => $attachment['name'], 'type' => $attachment['type'], 'content' => $attachment['content']];
            }
        }

        return null;
    }

    public function raw(string $folder, int $uid): ?string
    {
        return $this->stored($folder, $uid)['raw'] ?? null;
    }

    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        foreach ($uids as $uid) {
            if (! isset($this->folders[$folder]['messages'][$uid])) {
                continue;
            }

            $flags = array_values(array_diff($this->folders[$folder]['messages'][$uid]['flags'], [$flag]));
            $this->folders[$folder]['messages'][$uid]['flags'] = $on ? [...$flags, $flag] : $flags;
        }
    }

    public function move(string $folder, array $uids, string $target): void
    {
        if (! isset($this->folders[$target])) {
            throw WebmailUnavailable::because("Le dossier {$target} n’existe pas.");
        }

        foreach ($uids as $uid) {
            $message = $this->folders[$folder]['messages'][$uid] ?? null;

            if ($message !== null) {
                unset($this->folders[$folder]['messages'][$uid]);
                $this->seed($target, collect($message)->except('uid')->all());
            }
        }
    }

    public function delete(string $folder, array $uids): void
    {
        foreach ($uids as $uid) {
            unset($this->folders[$folder]['messages'][$uid]);
        }
    }

    public function append(string $folder, string $raw, array $flags = []): void
    {
        $header = fn (string $name) => preg_match('/^'.$name.':\s*(.+)$/mi', $raw, $match) ? iconv_mime_decode(trim($match[1]), 0, 'UTF-8') : null;

        $this->seed($folder, [
            'subject' => $header('Subject') ?? '',
            'from' => ['name' => '', 'email' => (string) $header('From')],
            'to' => [],
            'flags' => $flags,
            'raw' => $raw,
            'text' => null,
        ]);
    }

    public function createFolder(string $path): void
    {
        $this->folders[$path] ??= ['delimiter' => '.', 'attributes' => [], 'messages' => []];
    }

    public function quota(): ?array
    {
        return $this->quota;
    }

    public function send(Email $email): void
    {
        if ($this->failSend) {
            throw WebmailUnavailable::because('Le message n’a pas pu être envoyé : le serveur d’envoi ne répond pas. Il est gardé dans le formulaire.');
        }

        $this->sent[] = $email;
    }

    public function disconnect(): void
    {
        $this->disconnects++;
    }

    /** @return list<array<string, mixed>> */
    private function filtered(string $folder, array $criteria): array
    {
        $messages = array_values($this->folders[$folder]['messages'] ?? []);
        $text = mb_strtolower((string) ($criteria['text'] ?? ''));

        $messages = array_filter($messages, fn ($message) => ($text === '' || str_contains(mb_strtolower($message['subject'].' '.$message['text'].' '.$message['from']['email'].' '.$message['from']['name']), $text))
            && (empty($criteria['unseen']) || ! in_array('\\Seen', $message['flags'], true))
            && (empty($criteria['flagged']) || in_array('\\Flagged', $message['flags'], true))
            && (empty($criteria['keyword']) || in_array($criteria['keyword'], $message['flags'], true)));

        usort($messages, fn ($a, $b) => $b['uid'] <=> $a['uid']);

        return array_values($messages);
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function summary(array $message): array
    {
        $flags = $message['flags'];

        return [
            'uid' => $message['uid'],
            'from' => $message['from'],
            'to' => $message['to'],
            'subject' => $message['subject'],
            'date' => $message['date'],
            'seen' => in_array('\\Seen', $flags, true),
            'flagged' => in_array('\\Flagged', $flags, true),
            'answered' => in_array('\\Answered', $flags, true),
            'draft' => in_array('\\Draft', $flags, true),
            'keywords' => array_values(array_filter($flags, fn ($flag) => ! str_starts_with($flag, '\\'))),
            'has_attachments' => collect($message['attachments'])->contains(fn ($attachment) => ! ($attachment['inline'] ?? false)),
        ];
    }
}
