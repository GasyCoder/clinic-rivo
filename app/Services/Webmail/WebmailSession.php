<?php

namespace App\Services\Webmail;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * ADR-195 — la boîte ouverte et son mot de passe, saisi à l'ouverture, gardés
 * chiffrés dans la session et nulle part ailleurs : jamais en base, jamais dans
 * un journal (ADR-190). Ils disparaissent à la déconnexion de RIVO, quand on
 * ferme la boîte, ou dès que l'adresse n'est plus la même (suspendue, changée).
 *
 * ADR-200 — deux places, parce qu'il y a deux boîtes possibles :
 *  - `webmail.credentials` : la boîte choisie à l'écran d'ouverture (la sienne, ou
 *    celle d'un autre employé avec `webmail.open_any`) et son mot de passe ;
 *  - `webmail.own` : le mot de passe de SA boîte pour cette session — celui saisi
 *    à la connexion à RIVO (ADR-197 : le même mot de passe), ou celui qu'on a tapé
 *    une fois quand ils diffèrent. Ouvrir la boîte d'un collègue ne le perd pas :
 *    la refermer ramène directement à la sienne.
 */
class WebmailSession
{
    private const KEY = 'webmail.credentials';

    private const OWN_KEY = 'webmail.own';

    /** Le mot de passe de sa boîte vient de la connexion à RIVO. */
    public const VIA_LOGIN = 'login';

    /** Le mot de passe de sa boîte a été tapé à l'écran d'ouverture. */
    public const VIA_TYPED = 'typed';

    public function __construct(private readonly Session $session) {}

    public function remember(WebmailBox $box, #[\SensitiveParameter] string $password): void
    {
        $this->session->put(self::KEY, Crypt::encryptString(json_encode([
            'box' => $box->toArray(),
            'password' => $password,
        ], JSON_THROW_ON_ERROR)));
    }

    /** La boîte ouverte dans cette session, telle qu'elle a été choisie. */
    public function box(): ?WebmailBox
    {
        $data = $this->read();

        return is_array($data['box'] ?? null) ? WebmailBox::fromArray($data['box']) : null;
    }

    /** Le mot de passe de cette boîte, s'il a été saisi dans cette session. */
    public function passwordFor(?WebmailBox $box): ?string
    {
        if ($box === null) {
            return null;
        }

        $data = $this->read();
        $stored = is_array($data['box'] ?? null) ? WebmailBox::fromArray($data['box']) : null;

        if ($stored === null || ! $stored->is($box)) {
            return null;
        }

        return is_string($data['password'] ?? null) ? $data['password'] : null;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Le mot de passe de sa propre boîte pour cette session, lié à son adresse :
     * une adresse changée entre-temps ne s'ouvre pas avec lui.
     */
    public function rememberOwn(WebmailBox $box, #[\SensitiveParameter] string $password, string $via, bool $verified): void
    {
        $this->session->put(self::OWN_KEY, Crypt::encryptString(json_encode([
            'address' => $box->address,
            'password' => $password,
            'via' => $via,
            'verified' => $verified,
        ], JSON_THROW_ON_ERROR)));
    }

    /** Le mot de passe de sa boîte, s'il est connu pour cette adresse dans cette session. */
    public function ownPasswordFor(?WebmailBox $box): ?string
    {
        $data = $this->readOwn();

        if ($box === null || ! $box->own || $box->portal || $data === null || ($data['address'] ?? null) !== $box->address) {
            return null;
        }

        return is_string($data['password'] ?? null) ? $data['password'] : null;
    }

    /** D'où vient le mot de passe de sa boîte : `login`, `typed`, ou `null` s'il n'est pas connu. */
    public function ownVia(): ?string
    {
        $via = $this->readOwn()['via'] ?? null;

        return in_array($via, [self::VIA_LOGIN, self::VIA_TYPED], true) ? $via : null;
    }

    /** Le serveur de messagerie l'a déjà accepté dans cette session. */
    public function ownVerified(): bool
    {
        return (bool) ($this->readOwn()['verified'] ?? false);
    }

    public function markOwnVerified(): void
    {
        $data = $this->readOwn();

        if ($data === null || ! is_string($data['password'] ?? null) || ! is_string($data['address'] ?? null)) {
            return;
        }

        $data['verified'] = true;
        $this->session->put(self::OWN_KEY, Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function forgetOwn(): void
    {
        $this->session->forget(self::OWN_KEY);
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        return $this->decrypt(self::KEY);
    }

    /** @return array<string, mixed>|null */
    private function readOwn(): ?array
    {
        return $this->decrypt(self::OWN_KEY);
    }

    /** @return array<string, mixed>|null */
    private function decrypt(string $key): ?array
    {
        $stored = $this->session->get($key);

        if (! is_string($stored)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->session->forget($key);

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
