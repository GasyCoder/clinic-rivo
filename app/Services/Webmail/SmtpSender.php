<?php

namespace App\Services\Webmail;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * ADR-195 — l'envoi, par le serveur d'envoi de l'hébergeur, authentifié avec le mot
 * de passe de la boîte.
 *
 * Séparé de la lecture IMAP : l'envoi a sa propre connexion, et il se fait toujours
 * dans la requête qui l'a demandé — même quand la lecture passe par la connexion
 * gardée ouverte (MailboxConnectionPool) —, pour que le « QUIT » parte après la
 * réponse à l'écran.
 */
final class SmtpSender
{
    /**
     * @throws WebmailUnavailable
     */
    public static function send(string $address, #[\SensitiveParameter] string $password, Email $email): void
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
        $transport->setUsername($address);
        $transport->setPassword($password);
        $transport->getStream()->setTimeout((float) (config('rivo.webmail.timeout') ?? 20));

        if (($smtp['encryption'] ?? 'ssl') === 'none') {
            $transport->setAutoTls(false);
        }

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            self::close($transport);
            // Une trace sans pile d'appels : ses arguments pourraient porter le mot de passe.
            Log::warning('Messagerie : envoi refusé', [
                'address' => $address,
                'error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 300),
            ]);

            throw WebmailUnavailable::because(str_contains(strtolower($exception->getMessage()), 'auth')
                ? 'Le serveur d’envoi refuse ce mot de passe.'
                : 'Le message n’a pas pu être envoyé : le serveur d’envoi ne répond pas. Il est gardé dans le formulaire.');
        }

        // Le message est parti : le « QUIT » attend la fin de la requête, pas l'écran.
        app()->terminating(fn () => self::close($transport));
    }

    private static function close(EsmtpTransport $transport): void
    {
        try {
            $transport->stop();
        } catch (Throwable) {
        }
    }
}
