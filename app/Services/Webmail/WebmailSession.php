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
 */
class WebmailSession
{
    private const KEY = 'webmail.credentials';

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

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $stored = $this->session->get(self::KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->forget();

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
