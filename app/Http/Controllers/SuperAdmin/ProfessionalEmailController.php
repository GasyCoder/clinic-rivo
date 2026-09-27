<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Http\Requests\ProfessionalEmail\ProfessionalEmailRules;
use App\Services\MailHosting\CpanelMailboxClient;
use App\Services\MailHosting\MailboxProvisioner;
use App\Services\MailHosting\RemoteMailboxRegistry;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-190 — adresses email professionnelles, depuis le portail.
 *
 * Le portail agit chez l'hébergeur puis le dit au site par son API (ADR-004).
 * Le chemin (hébergeur d'abord, reprise sans double création) est celui du
 * site lui-même quand un RH a reçu le droit : MailboxProvisioner.
 *
 * Un mot de passe n'est ni enregistré, ni journalisé : il part dans la
 * réponse JSON, affiché une seule fois à l'écran de celui qui agit.
 */
class ProfessionalEmailController extends Controller
{
    /** Les gestes qui passent par l'hébergeur : chacun peut préparer la connexion. */
    private const HOSTING_ABILITIES = [
        'professional_emails.create',
        'professional_emails.deactivate',
        'professional_emails.activate',
        'professional_emails.update',
    ];

    use RespondsToSiteApi;

    public function __construct(
        private readonly PortalSiteApiClient $sites,
        private readonly RemoteMailboxRegistry $registry,
        private readonly MailboxProvisioner $provisioner,
        private readonly CpanelMailboxClient $hosting,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('SuperAdmin/ProfessionalEmails/Index', [
            'sites' => $this->sites->professionalMailboxesForAllSites($request->user()),
            'hosting' => [
                'configured' => $this->provisioner->hostingConfigured(),
                'auth_mode' => $this->hosting->authMode(),
                'server' => $this->hosting->server(),
                'domain' => ProfessionalEmailAddress::domain(),
                'quota_mb' => (int) config('rivo.professional_email.hosting.quota_mb', 1024),
            ],
        ]);
    }

    public function create(Request $request, string $site, string $mailbox): JsonResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(...ProfessionalEmailRules::localPart());

        return $this->json($this->provisioner->create($this->registry, mb_strtoupper($site), $mailbox, $validated['local_part'], $request->user()));
    }

    public function direct(Request $request, string $site): JsonResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(...ProfessionalEmailRules::direct());

        return $this->json($this->provisioner->direct($this->registry, mb_strtoupper($site), $validated['employee_uuid'], $validated['local_part'], $request->user()));
    }

    public function reject(Request $request, string $site, string $mailbox): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(ProfessionalEmailRules::reason());

        return $this->respond($this->registry->command(mb_strtoupper($site), $mailbox, 'reject', $validated, $request->user()), 'Demande refusée.');
    }

    public function suspend(Request $request, string $site, string $mailbox): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(ProfessionalEmailRules::reason());

        return $this->redirect($this->provisioner->suspend($this->registry, mb_strtoupper($site), $mailbox, $validated['reason'], $request->user()));
    }

    public function reactivate(Request $request, string $site, string $mailbox): RedirectResponse
    {
        $this->assertSite($site);

        return $this->redirect($this->provisioner->reactivate($this->registry, mb_strtoupper($site), $mailbox, $request->user()));
    }

    /** Lecture seule chez l'hébergeur : rien n'est créé ni enregistré. */
    public function check(): JsonResponse
    {
        return $this->json($this->provisioner->checkConnection());
    }

    /**
     * Connexion faite d'avance, à l'ouverture d'une fenêtre qui touchera l'hébergeur.
     * Rien n'est créé ; réservée à qui peut agir chez l'hébergeur.
     */
    public function prepare(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(self::HOSTING_ABILITIES), 403);

        return $this->json($this->provisioner->prepareConnection());
    }

    public function resetPassword(Request $request, string $site, string $mailbox): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($this->provisioner->resetPassword($this->registry, mb_strtoupper($site), $mailbox, $request->user()));
    }

    /** @param array<string, mixed> $result */
    private function json(array $result): JsonResponse
    {
        return response()->json(collect($result)->except(['ok', 'status'])->all(), $result['status']);
    }

    /** @param array<string, mixed> $result */
    private function redirect(array $result): RedirectResponse
    {
        return $result['ok']
            ? back()->with('status', $result['message'])
            : back()->withErrors(['mailbox' => $result['message']]);
    }
}
