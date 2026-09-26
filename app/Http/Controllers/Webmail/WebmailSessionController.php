<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalMailbox;
use App\Services\Audit\Auditor;
use App\Services\Webmail\MailServerFactory;
use App\Services\Webmail\WebmailAccess;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailBox;
use App\Services\Webmail\WebmailSession;
use App\Services\Webmail\WebmailUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-195 — ouvrir et fermer une boîte. Sa propre boîte avec `webmail.view`, celle
 * d'un autre employé du site avec `webmail.open_any` — choisie parmi les adresses
 * actives, relues côté serveur, jamais décrites par le navigateur.
 *
 * Le portail n'a rien à ouvrir ni à fermer : sa boîte est réglée dans son .env et
 * le Super Admin arrive directement dans la boîte de réception (amendement du
 * 2026-09-26).
 *
 * Le mot de passe est vérifié par le serveur de messagerie lui-même, puis gardé
 * chiffré dans la session et nulle part ailleurs (ADR-190). Chaque ouverture,
 * refus et fermeture est audité — en disant quand la boîte n'est pas celle du
 * compte —, jamais le mot de passe.
 */
class WebmailSessionController extends Controller
{
    public function create(Request $request, WebmailAccess $access, WebmailSession $session): Response|RedirectResponse
    {
        $user = $request->user();
        $current = $access->current($user);
        $own = $access->ownBox($user);

        // Le portail : sa boîte s'ouvre sans rien saisir ni choisir, « changer » compris.
        if ($own?->portal || (! $request->boolean('changer') && $access->password($current) !== null)) {
            return redirect()->route('webmail.index');
        }

        return Inertia::render('Webmail/Connect', [
            'own' => $own?->toArray(),
            'others' => array_map(fn (WebmailBox $box) => $box->toArray(), $access->others($user)),
            'canOpenAny' => $access->canOpenAny($user),
            // La boîte proposée d'abord : celle déjà choisie, sinon la sienne, sinon aucune.
            'selected' => $current?->uuid ?? $own?->uuid,
            'openBox' => $access->password($current) !== null ? $current?->toArray() : null,
        ]);
    }

    public function store(Request $request, WebmailAccess $access, WebmailSession $session, MailServerFactory $factory, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate(
            [
                'password' => ['required', 'string', 'max:200'],
                'mailbox' => ['nullable', 'uuid'],
            ],
            ['password.required' => 'Saisissez le mot de passe de la boîte.'],
        );
        $box = $this->chosenBox($request, $access, $validated);

        // La boîte du portail n'attend aucun mot de passe : celui du .env fait foi.
        if ($box->portal) {
            return redirect()->route('webmail.index');
        }

        try {
            $factory->connect($box->address, $validated['password'])->disconnect();
        } catch (WebmailAuthenticationFailed $exception) {
            $auditor->record('webmail.connect_failed', entity: $this->record($box), newValues: $this->auditValues($box), reason: 'Mot de passe refusé par le serveur de messagerie.', module: 'webmail');

            return back()->withErrors(['password' => $exception->getMessage()]);
        } catch (WebmailUnavailable $exception) {
            return back()->withErrors(['password' => $exception->getMessage()]);
        }

        $session->remember($box, $validated['password']);
        $request->session()->regenerate();
        $auditor->record('webmail.connect', entity: $this->record($box), newValues: $this->auditValues($box), module: 'webmail');

        return redirect()->intended(route('webmail.index'));
    }

    public function destroy(Request $request, WebmailAccess $access, WebmailSession $session, Auditor $auditor): RedirectResponse
    {
        $box = $access->current($request->user());

        // La boîte du portail ne se ferme pas : aucun mot de passe n'a été saisi.
        if ($box?->portal) {
            return redirect()->route('webmail.index');
        }

        $session->forget();
        $auditor->record('webmail.disconnect', entity: $box ? $this->record($box) : null, newValues: $box ? $this->auditValues($box) : [], module: 'webmail');

        return redirect()->route('webmail.connect')->with('status', 'Boîte fermée : son mot de passe a été oublié par RIVO.');
    }

    /**
     * La boîte demandée : la sienne quand rien d'autre n'est demandé, sinon une
     * adresse active d'un autre employé — seulement avec `webmail.open_any`.
     *
     * @param  array<string, mixed>  $validated
     */
    private function chosenBox(Request $request, WebmailAccess $access, array $validated): WebmailBox
    {
        $user = $request->user();
        $own = $access->ownBox($user);
        $uuid = $validated['mailbox'] ?? null;

        if ($uuid === null || ($own !== null && $own->uuid === $uuid)) {
            if ($own === null) {
                throw ValidationException::withMessages(['mailbox' => 'Choisissez la boîte à ouvrir.']);
            }

            return $own;
        }

        abort_unless($access->canOpenAny($user), 403, 'Ouvrir la boîte d’un autre employé demande le droit « '.WebmailAccess::OPEN_ANY.' ».');

        return $access->findOther($user, $uuid)
            ?? throw ValidationException::withMessages(['mailbox' => 'Cette boîte n’est pas (ou plus) active.']);
    }

    /** L'adresse enregistrée sur ce site, quand elle y vit — sur le portail, elle vit sur un site. */
    private function record(WebmailBox $box): ?ProfessionalMailbox
    {
        return WebmailAccess::onPortal() ? null : ProfessionalMailbox::query()->where('uuid', $box->uuid)->first();
    }

    /** @return array<string, mixed> */
    private function auditValues(WebmailBox $box): array
    {
        return [
            'address' => $box->address,
            'site' => $box->siteCode,
            'titular' => $box->owner,
            // Une boîte qui n'est pas celle du compte se dit dans l'audit.
            'own_mailbox' => $box->own,
        ];
    }
}
