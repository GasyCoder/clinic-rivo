<?php

namespace App\Services\MailHosting;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * ADR-190 — les boîtes email chez l'hébergeur, par l'API cPanel (UAPI).
 *
 * Avec un jeton API (de préférence) :
 *   POST {url}/execute/Email/{fonction}
 *   Authorization: cpanel {utilisateur}:{jeton}
 *
 * Avec le mot de passe du compte (l'offre n'ouvre pas les jetons) : o2switch
 * refuse l'authentification Basic sur l'API (401 même avec le bon mot de passe,
 * constaté le 2026-09-25). Le portail ouvre donc une session comme le navigateur,
 * appelle l'API par cette session, puis la ferme :
 *   POST {url}/login/?login_only=1                  → cookie cpsession + /cpsessNNN
 *   POST {url}/cpsessNNN/execute/Email/{fonction}
 *
 * La connexion cPanel est l'étape la plus lente (2 à 9 s selon l'heure) : la
 * session est gardée quelques minutes, chiffrée dans le cache, et réutilisée
 * par les opérations suivantes. Une session expirée répond 401 sans rien
 * exécuter : une nouvelle est ouverte et l'appel rejoué, une seule fois — une
 * boîte n'est donc jamais créée deux fois. « Tester la connexion » ouvre
 * toujours une session neuve, pour éprouver vraiment le mot de passe.
 *
 * Lu par le portail seul : le jeton donne accès à tout l'hébergement et ne se
 * pose jamais sur un site clinique. Les paramètres partent dans le corps, pas
 * dans l'URL, pour qu'un mot de passe n'apparaisse dans aucun journal. Rien
 * n'est rejoué automatiquement : créer deux fois une boîte n'est pas anodin.
 */
final class CpanelMailboxClient
{
    private const PASSWORD_REFUSED = 'L’hébergeur a refusé la connexion : vérifiez l’utilisateur et le mot de passe cPanel, que la double authentification n’est pas active sur ce compte, et que l’adresse IP de ce serveur est autorisée.';

    public function configured(): bool
    {
        return $this->setting('url') !== '' && $this->setting('user') !== '' && $this->authMode() !== null;
    }

    /**
     * Comment le portail s'authentifie : un jeton API si l'offre le permet, sinon
     * le mot de passe du compte cPanel (moins sûr : il ouvre aussi l'interface et
     * ne se révoque pas séparément). Le jeton l'emporte quand les deux existent.
     */
    public function authMode(): ?string
    {
        return match (true) {
            $this->setting('token') !== '' => 'token',
            $this->setting('password') !== '' => 'password',
            default => null,
        };
    }

    /** L'adresse du serveur, sans le jeton : ce que l'écran peut montrer. */
    public function server(): ?string
    {
        $url = $this->setting('url');

        return $url !== '' ? (parse_url($url, PHP_URL_HOST) ?: $url) : null;
    }

    /**
     * Vérifie l'accès sans rien modifier : lit la liste des boîtes du compte.
     *
     * @return int le nombre de boîtes existantes
     */
    public function check(): int
    {
        $json = $this->call('list_pops', [], freshSession: true);

        return is_array($json['data'] ?? null) ? count($json['data']) : 0;
    }

    /**
     * Ouvre une session à l'avance, pendant que l'écran se remplit : l'opération qui
     * suit n'attend plus la connexion, l'étape la plus lente. Ne touche à aucune
     * boîte. Sans effet avec un jeton, sans session gardée, ou si une session l'est déjà.
     */
    public function prepare(): void
    {
        if (! $this->configured() || $this->authMode() !== 'password'
            || $this->sessionMinutes() === 0 || $this->cachedSession() !== null) {
            return;
        }

        try {
            $this->openSession();
        } catch (ConnectionException) {
            throw new MailHostingException('L’hébergeur ne répond pas. Réessayez dans un instant.');
        }
    }

    public function create(string $address, string $password): void
    {
        [$localPart, $domain] = $this->split($address);

        $this->call('add_pop', [
            'email' => $localPart,
            'domain' => $domain,
            'password' => $password,
            'quota' => max(0, (int) config('rivo.professional_email.hosting.quota_mb', 1024)),
        ]);
    }

    /** Bloque la connexion, l'envoi et la lecture ; la boîte et ses messages restent. */
    public function suspend(string $address): void
    {
        $this->call('suspend_login', ['email' => $address]);
    }

    public function unsuspend(string $address): void
    {
        $this->call('unsuspend_login', ['email' => $address]);
    }

    public function changePassword(string $address, string $password): void
    {
        [$localPart, $domain] = $this->split($address);

        $this->call('passwd_pop', ['email' => $localPart, 'domain' => $domain, 'password' => $password]);
    }

    /**
     * @param  array<string, scalar>  $parameters
     * @return array<string, mixed>
     */
    private function call(string $function, array $parameters, bool $freshSession = false): array
    {
        if (! $this->configured()) {
            throw new MailHostingException('L’accès à l’hébergeur n’est pas configuré sur ce serveur (RIVO_MAIL_HOSTING_URL, _USER, et _TOKEN ou _PASSWORD).');
        }

        try {
            if ($this->authMode() === 'token') {
                $response = $this->request()
                    ->withHeaders(['Authorization' => 'cpanel '.$this->setting('user').':'.$this->setting('token')])
                    ->post($this->base().'/execute/Email/'.$function, $parameters);
            } else {
                $response = $this->callWithSession($function, $parameters, $freshSession);
            }
        } catch (ConnectionException) {
            throw new MailHostingException('L’hébergeur ne répond pas. Rien n’a été modifié ; réessayez dans un instant.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new MailHostingException($this->authMode() === 'token'
                ? 'L’hébergeur a refusé l’accès : vérifiez le jeton API et que l’adresse IP de ce serveur est autorisée.'
                : self::PASSWORD_REFUSED);
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new MailHostingException('L’hébergeur a répondu de façon inattendue (HTTP '.$response->status().'). Rien n’a été confirmé.');
        }

        if ((int) ($json['status'] ?? 0) !== 1) {
            $errors = collect($json['errors'] ?? [])->filter()->map(fn ($error) => trim((string) $error))->implode(' ');

            throw new MailHostingException('L’hébergeur a refusé : '.($errors !== '' ? $errors : 'raison non précisée.'));
        }

        return $json;
    }

    /** @param  array<string, scalar>  $parameters */
    private function callWithSession(string $function, array $parameters, bool $fresh): Response
    {
        $session = $fresh ? null : $this->cachedSession();
        $reused = $session !== null;
        $session ??= $this->openSession();

        $response = $this->execute($session, $function, $parameters);

        // Session expirée ou fermée : 401, et rien n'a été exécuté. Une nouvelle, une fois.
        if ($reused && in_array($response->status(), [401, 403], true)) {
            Cache::forget($this->sessionKey());
            $session = $this->openSession();
            $response = $this->execute($session, $function, $parameters);
        }

        if ($this->sessionMinutes() === 0) {
            // Rien n'est gardé : la session est fermée après la réponse, sans faire attendre.
            defer(fn () => $this->closeSession($session));
        }

        return $response;
    }

    /**
     * @param  array{path: string, cookie: string}  $session
     * @param  array<string, scalar>  $parameters
     */
    private function execute(array $session, string $function, array $parameters): Response
    {
        return $this->request()
            ->withHeaders(['Cookie' => 'cpsession='.$session['cookie']])
            ->post($this->base().$session['path'].'/execute/Email/'.$function, $parameters);
    }

    /**
     * Ouvre une session cPanel avec le mot de passe du compte. Le mot de passe part
     * dans le corps de la requête, jamais dans l'URL. La session est gardée pour
     * les opérations suivantes ; celle qu'elle remplace est fermée après la réponse.
     *
     * @return array{path: string, cookie: string}
     */
    private function openSession(): array
    {
        $response = $this->request()->post($this->base().'/login/?login_only=1', [
            'user' => $this->setting('user'),
            'pass' => $this->setting('password'),
        ]);

        $json = $response->json();
        $path = is_array($json) ? (string) ($json['security_token'] ?? '') : '';
        $cookie = $this->sessionCookie($response);

        // Mauvais mot de passe : 401 et status 0. Double authentification active :
        // aucun jeton de session n'est rendu. Dans les deux cas, rien n'est tenté.
        if (! $response->successful() || (int) ($json['status'] ?? 0) !== 1
            || preg_match('#^/cpsess\d+$#', $path) !== 1 || $cookie === null) {
            Cache::forget($this->sessionKey());

            throw new MailHostingException(self::PASSWORD_REFUSED);
        }

        $session = ['path' => $path, 'cookie' => $cookie];

        if (($minutes = $this->sessionMinutes()) > 0) {
            $previous = $this->cachedSession();
            Cache::put($this->sessionKey(), Crypt::encrypt($session), now()->addMinutes($minutes));

            if ($previous !== null && $previous['path'] !== $path) {
                defer(fn () => $this->closeSession($previous));
            }
        }

        return $session;
    }

    /** @return array{path: string, cookie: string}|null */
    private function cachedSession(): ?array
    {
        $stored = Cache::get($this->sessionKey());
        if (! is_string($stored)) {
            return null;
        }

        try {
            $session = Crypt::decrypt($stored);
        } catch (DecryptException) {
            return null;
        }

        return is_array($session) && is_string($session['path'] ?? null) && is_string($session['cookie'] ?? null)
            ? ['path' => $session['path'], 'cookie' => $session['cookie']]
            : null;
    }

    private function sessionKey(): string
    {
        return 'rivo:cpanel-session:'.sha1($this->base().'|'.$this->setting('user'));
    }

    /** Durée pendant laquelle une session est réutilisée ; 0 = une session par opération. */
    private function sessionMinutes(): int
    {
        return max(0, (int) config('rivo.professional_email.hosting.session_minutes', 10));
    }

    /**
     * Ferme la session ; un échec ici ne remet pas en cause l’opération déjà faite.
     *
     * @param  array{path: string, cookie: string}  $session
     */
    private function closeSession(array $session): void
    {
        try {
            $this->request()
                ->withHeaders(['Cookie' => 'cpsession='.$session['cookie']])
                ->get($this->base().$session['path'].'/logout/');
        } catch (\Throwable) {
            // cPanel expire de lui-même une session oubliée.
        }
    }

    private function sessionCookie(Response $response): ?string
    {
        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $line) {
            if (preg_match('/^cpsession=([^;]+)/', $line, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        return Http::asForm()->acceptJson()
            ->timeout(max(1, (int) config('rivo.professional_email.hosting.timeout', 15)));
    }

    private function base(): string
    {
        return rtrim($this->setting('url'), '/');
    }

    /** @return array{0: string, 1: string} */
    private function split(string $address): array
    {
        [$localPart, $domain] = array_pad(explode('@', mb_strtolower(trim($address)), 2), 2, '');

        return [$localPart, $domain];
    }

    private function setting(string $key): string
    {
        return trim((string) config('rivo.professional_email.hosting.'.$key));
    }
}
