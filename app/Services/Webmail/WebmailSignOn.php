<?php

namespace App\Services\Webmail;

use App\Models\User;
use App\Services\Webmail\KeepAlive\MailboxConnectionPool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * ADR-200 — déjà connecté à RIVO, on ne retape pas son mot de passe pour lire
 * ses emails.
 *
 * Depuis l'ADR-197, l'adresse professionnelle et le compte RIVO d'un employé
 * reçoivent le même mot de passe. À la connexion, celui qui vient d'être saisi est
 * donc gardé — chiffré, dans la session, comme celui qu'on tapait à l'écran
 * d'ouverture (ADR-195) — et ouvre SA boîte directement.
 *
 * Seulement pour un compte qui a une adresse active et le droit de l'ouvrir, sur un
 * site. Rien n'est vérifié ici : la connexion à RIVO n'attend pas le serveur de
 * messagerie et n'échoue jamais à cause de lui. Le serveur juge au premier usage ;
 * s'il refuse (mot de passe de boîte différent), le mot de passe est oublié aussitôt
 * et celui de la boîte est demandé une fois. Jamais en base, jamais dans un journal
 * ni dans l'audit ; oublié à la déconnexion.
 *
 * Amendement du 2026-09-26 — « Se souvenir de moi ». La session expire après deux
 * heures sans activité ; RIVO reconnecte alors le compte par son cookie, sans mot de
 * passe saisi, et la messagerie redemandait celui de la boîte. Quand « Se souvenir de
 * moi » est coché, le mot de passe de sa boîte est donc gardé aussi sur l'appareil,
 * dans un cookie chiffré (HttpOnly, même durée que le cookie de RIVO), lié au compte
 * et à l'adresse : la reconnexion le remet dans la session. Rien en base. Il est
 * effacé à la déconnexion, à une connexion sans « Se souvenir de moi », quand le
 * serveur le refuse, et quand on ferme sa boîte.
 */
final class WebmailSignOn
{
    /** Le mot de passe de sa boîte sur l'appareil, le temps de « Se souvenir de moi ». */
    public const DEVICE_COOKIE = 'rivo_webmail_key';

    public function __construct(
        private readonly WebmailAccess $access,
        private readonly WebmailSession $session,
        private readonly MailboxConnectionPool $pool,
    ) {}

    public function rememberAtLogin(User $user, #[\SensitiveParameter] string $password, bool $remember = false): void
    {
        if (WebmailAccess::onPortal()) {
            $this->warmPortal($user);

            return;
        }

        if ($password === '') {
            return;
        }

        try {
            $box = $this->access->ownBox($user);

            if ($box === null) {
                $this->forgetDevice();

                return;
            }

            $this->session->rememberOwn($box, $password, WebmailSession::VIA_LOGIN, verified: false);
            // La connexion à sa boîte s'établit pendant qu'il arrive sur RIVO : le premier clic
            // sur « Messagerie » la trouve prête (MailboxConnectionPool).
            $this->pool->warm($box->address, $password);

            // Sans « Se souvenir de moi », la session suffit : une nouvelle connexion le
            // redonnera. Ce qu'un autre compte a laissé sur ce poste partagé part aussi.
            $remember ? $this->keepOnDevice($user, $box, $password) : $this->forgetDevice();
        } catch (Throwable) {
            // La messagerie ne doit jamais empêcher d'entrer dans RIVO.
        }
    }

    /**
     * Reconnexion par « Se souvenir de moi » : aucun mot de passe n'a été saisi.
     * Celui gardé sur l'appareil pour ce compte et cette adresse revient dans la
     * session ; un cookie laissé par un autre compte, ou pour une adresse qui n'est
     * plus la sienne, est effacé.
     */
    public function restoreFromDevice(User $user): void
    {
        if (WebmailAccess::onPortal()) {
            return;
        }

        try {
            $data = $this->readDevice();

            if ($data === null) {
                return;
            }

            $box = $this->access->ownBox($user);

            if ($box === null || ($data['user'] ?? null) !== (string) $user->getAuthIdentifier() || ($data['address'] ?? null) !== $box->address || ! is_string($data['password'] ?? null)) {
                $this->forgetDevice();

                return;
            }

            if ($this->session->ownPasswordFor($box) === null) {
                $this->session->rememberOwn($box, $data['password'], WebmailSession::VIA_LOGIN, verified: false);
                $this->pool->warm($box->address, $data['password']);
            }
        } catch (Throwable) {
            // La messagerie ne doit jamais empêcher d'entrer dans RIVO.
        }
    }

    /**
     * Sa boîte, tapée une fois parce que son mot de passe n'est pas celui de RIVO :
     * gardée aussi sur l'appareil quand « Se souvenir de moi » y est actif, pour ne
     * pas la retaper à chaque reconnexion.
     */
    public function keepTypedOnDevice(Request $request, User $user, WebmailBox $box, #[\SensitiveParameter] string $password): void
    {
        if (! $box->own || $box->portal || ! $request->cookies->has(Auth::guard('web')->getRecallerName())) {
            return;
        }

        $this->keepOnDevice($user, $box, $password);
    }

    /**
     * Ferme les connexions gardées ouvertes de cette session : sa boîte, et celle d'un
     * collègue ouverte à l'écran d'ouverture. À la déconnexion de RIVO.
     */
    public function stopConnections(User $user): void
    {
        try {
            foreach (array_filter([$this->access->current($user), $this->access->ownBox($user)]) as $box) {
                if (! $box->portal && ($password = $this->access->password($box)) !== null) {
                    $this->pool->stop($box->address, $password);
                }
            }
        } catch (Throwable) {
            // Elle se fermera seule après quelques minutes sans usage.
        }
    }

    /** Le portail : sa boîte, réglée dans son .env, est prête dès la connexion. */
    private function warmPortal(User $user): void
    {
        try {
            $box = $this->access->ownBox($user);

            if ($box !== null && ($password = $this->access->password($box)) !== null) {
                $this->pool->warm($box->address, $password);
            }
        } catch (Throwable) {
            // La messagerie ne doit jamais empêcher d'entrer dans RIVO.
        }
    }

    public function forgetDevice(): void
    {
        if (request()->cookies->has(self::DEVICE_COOKIE) || Cookie::hasQueued(self::DEVICE_COOKIE)) {
            Cookie::queue(Cookie::forget(self::DEVICE_COOKIE));
        }
    }

    private function keepOnDevice(User $user, WebmailBox $box, #[\SensitiveParameter] string $password): void
    {
        $value = Crypt::encryptString(json_encode([
            'user' => (string) $user->getAuthIdentifier(),
            'address' => $box->address,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));

        // La durée de « Se souvenir de moi » de RIVO : ni plus, ni moins.
        Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $value, (int) (config('auth.guards.web.remember') ?? 576000)));
    }

    /** @return array<string, mixed>|null */
    private function readDevice(): ?array
    {
        $stored = request()->cookie(self::DEVICE_COOKIE);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->forgetDevice();

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
