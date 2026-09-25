<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfessionalEmail\ProfessionalEmailRules;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Services\Administration\ProfessionalMailboxDirectory;
use App\Services\Administration\ProfessionalMailboxWorkflow;
use App\Services\Catalog\CatalogActor;
use App\Services\MailHosting\CpanelMailboxClient;
use App\Services\MailHosting\LocalMailboxRegistry;
use App\Services\MailHosting\MailboxProvisioner;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-190 — les adresses email professionnelles, côté RH du site.
 *
 * Tout RH qui a `professional_emails.view` voit les adresses de son site ; avec
 * `professional_emails.request` il en demande. Les autres gestes — créer,
 * refuser, suspendre, réactiver, nouveau mot de passe — suivent le droit que le
 * Super Admin lui a accordé, et exigent que les accès à l'hébergeur soient
 * aussi posés sur ce site (amendement du 2026-09-25). Le chemin est celui du
 * portail : MailboxProvisioner. Servi au site et au portail (routes/hr.php, ADR-187).
 */
class ProfessionalMailboxController extends Controller
{
    /** Les gestes qui passent par l'hébergeur : chacun peut préparer la connexion. */
    private const HOSTING_ABILITIES = [
        'professional_emails.create',
        'professional_emails.deactivate',
        'professional_emails.activate',
        'professional_emails.update',
    ];

    public function __construct(
        private readonly LocalMailboxRegistry $registry,
        private readonly MailboxProvisioner $provisioner,
    ) {}

    public function index(Request $request, ProfessionalMailboxDirectory $directory, CpanelMailboxClient $hosting): Response
    {
        $listing = $directory->listing($request->user()->can('professional_emails.request'));

        return Inertia::render('Administration/ProfessionalEmails/Index', [
            // La même forme que la liste du portail, pour un seul site : un seul écran les montre.
            'sites' => [['site' => $listing['meta']['site'], 'ok' => true, 'status' => 'ONLINE', 'message' => null, ...$listing]],
            'hosting' => [
                'configured' => $this->provisioner->hostingConfigured(),
                'auth_mode' => $hosting->authMode(),
                'server' => $hosting->server(),
                'domain' => ProfessionalEmailAddress::domain(),
                'quota_mb' => (int) config('rivo.professional_email.hosting.quota_mb', 1024),
            ],
        ]);
    }

    public function store(Request $request, Employee $employee, ProfessionalMailboxWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['local_part.required' => 'Indiquez la partie de l’adresse avant « @ ».']);

        $mailbox = $workflow->request($employee, $validated, CatalogActor::fromUser($request->user()));

        return back()->with('status', "Demande enregistrée : {$mailbox->address}.");
    }

    public function cancel(Request $request, ProfessionalMailbox $mailbox, ProfessionalMailboxWorkflow $workflow): RedirectResponse
    {
        $workflow->cancel($mailbox, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Demande d’adresse annulée.');
    }

    public function create(Request $request, string $mailbox): JsonResponse
    {
        $validated = $request->validate(...ProfessionalEmailRules::localPart());

        return $this->json($this->provisioner->create($this->registry, $this->site(), $mailbox, $validated['local_part'], $request->user()));
    }

    public function direct(Request $request): JsonResponse
    {
        $validated = $request->validate(...ProfessionalEmailRules::direct());

        return $this->json($this->provisioner->direct($this->registry, $this->site(), $validated['employee_uuid'], $validated['local_part'], $request->user()));
    }

    public function reject(Request $request, string $mailbox): RedirectResponse
    {
        $validated = $request->validate(ProfessionalEmailRules::reason());
        $result = $this->registry->command($this->site(), $mailbox, 'reject', $validated, $request->user());

        return $result['ok']
            ? back()->with('status', 'Demande refusée.')
            : back()->withErrors(['mailbox' => $result['message']]);
    }

    public function suspend(Request $request, string $mailbox): RedirectResponse
    {
        $validated = $request->validate(ProfessionalEmailRules::reason());

        return $this->redirect($this->provisioner->suspend($this->registry, $this->site(), $mailbox, $validated['reason'], $request->user()));
    }

    public function reactivate(Request $request, string $mailbox): RedirectResponse
    {
        return $this->redirect($this->provisioner->reactivate($this->registry, $this->site(), $mailbox, $request->user()));
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

    public function resetPassword(Request $request, string $mailbox): JsonResponse
    {
        return $this->json($this->provisioner->resetPassword($this->registry, $this->site(), $mailbox, $request->user()));
    }

    private function site(): string
    {
        return mb_strtoupper((string) config('rivo.site.code'));
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
