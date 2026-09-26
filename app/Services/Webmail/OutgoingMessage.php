<?php

namespace App\Services\Webmail;

use App\Support\Webmail\EmailHtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * ADR-194 — un message rédigé dans RIVO, prêt à partir. Le serveur relit tout :
 * les adresses, le corps (un jeu fermé de balises), les pièces jointes ; rien de
 * ce que le navigateur envoie n'est pris tel quel.
 */
final class OutgoingMessage
{
    public const MAX_RECIPIENTS = 50;

    /**
     * Les adresses d'un champ « À », « Cc » ou « Cci » : séparées par une virgule,
     * un point-virgule ou un retour à la ligne ; « Nom <adresse> » est accepté.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function recipients(?string $value): array
    {
        $parsed = self::parse($value);

        return ['valid' => array_keys($parsed['named']), 'invalid' => $parsed['invalid']];
    }

    /**
     * Les mêmes adresses, avec le nom saisi devant elles (« Dr Vola <vola@x.mg> ») :
     * le message et sa copie dans Envoyés montrent le nom, pas l'adresse seule.
     *
     * @return array{named: array<string, string>, invalid: list<string>}
     */
    private static function parse(?string $value): array
    {
        $named = [];
        $invalid = [];

        foreach (preg_split('/[,;\n]+/', (string) $value) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $hasName = preg_match('/^(.*)<([^>]+)>\s*$/', $chunk, $match) === 1;
            $email = strtolower(trim($hasName ? $match[2] : $chunk));
            $name = $hasName ? trim($match[1], " \t\"'") : '';

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $named[$email] ??= mb_substr(preg_replace('/[\r\n<>"]+/', ' ', $name) ?? '', 0, 120);
            } else {
                $invalid[] = $chunk;
            }
        }

        return ['named' => $named, 'invalid' => $invalid];
    }

    /**
     * @param  array{to?: ?string, cc?: ?string, bcc?: ?string, subject?: ?string, body_html?: ?string}  $data
     * @param  list<UploadedFile>  $uploads
     * @param  list<array{name: string, type: string, content: string}>  $forwarded  Pièces d'un message transféré.
     * @param  array<string, mixed>|null  $original  Le message auquel on répond (en-têtes du fil).
     */
    public static function build(
        string $fromAddress,
        string $fromName,
        array $data,
        array $uploads = [],
        array $forwarded = [],
        ?array $original = null,
    ): Email {
        $html = EmailHtmlSanitizer::forSending((string) ($data['body_html'] ?? ''));
        $email = (new Email)
            ->from(new Address($fromAddress, $fromName))
            ->subject(trim((string) ($data['subject'] ?? '')))
            ->html('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>')
            ->text(EmailHtmlSanitizer::toText($html));

        foreach (['to' => 'addTo', 'cc' => 'addCc', 'bcc' => 'addBcc'] as $field => $method) {
            foreach (self::parse($data[$field] ?? null)['named'] as $address => $name) {
                $email->{$method}(new Address($address, trim($name)));
            }
        }

        foreach ($uploads as $upload) {
            $email->attachFromPath($upload->getRealPath(), $upload->getClientOriginalName(), $upload->getClientMimeType() ?: null);
        }

        foreach ($forwarded as $file) {
            $email->attach($file['content'], $file['name'], $file['type']);
        }

        $domain = substr(strrchr($fromAddress, '@') ?: '@rivo.local', 1);
        $email->getHeaders()->addIdHeader('Message-ID', bin2hex(random_bytes(12)).'@'.$domain);

        if ($original !== null && filled($original['message_id'] ?? null)) {
            $email->getHeaders()->addIdHeader('In-Reply-To', (string) $original['message_id']);
            $references = trim(((string) ($original['references'] ?? '')).' <'.$original['message_id'].'>');
            $email->getHeaders()->addTextHeader('References', $references);
        }

        return $email;
    }
}
