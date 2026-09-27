<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\MailHosting\MailboxProvisioner;
use App\Services\StaffAccess\StaffAccessProvisioner;
use App\Services\StaffAccess\StaffAccessWatcher;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-197 — portail : l'accès du personnel. Les employés que le RH de chaque site
 * a ajoutés et qui n'ont pas encore de compte ; leur accès (adresse pro + compte
 * RIVO) créé en un geste, sans mot de passe — l'employé choisit le sien à sa
 * première connexion (ADR-202) ; l'annonce au RH du site.
 *
 * Tout passe par l'API des sites (ADR-004) ; l'hébergeur n'est appelé que d'ici.
 */
class StaffAccessController extends Controller
{
    use RespondsToSiteApi;

    public function __construct(
        private readonly PortalSiteApiClient $sites,
        private readonly StaffAccessProvisioner $provisioner,
        private readonly StaffAccessWatcher $watcher,
        private readonly MailboxProvisioner $mailboxes,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $results = $this->sites->staffAccessForAllSites($user);

        // La lecture sert aussi à prévenir les autres Super Admins des ajouts récents.
        $this->watcher->sync($user, $results, viewer: $user);

        return Inertia::render('SuperAdmin/StaffAccess/Index', [
            'sites' => collect($results)->map(fn (array $result) => [
                'code' => $result['site']['code'] ?? null,
                'name' => $result['site']['name'] ?? null,
                'ok' => (bool) ($result['ok'] ?? false),
                'status' => $result['status'] ?? null,
                'message' => ($result['ok'] ?? false) ? null : ($result['message'] ?? 'Site injoignable.'),
                'pending' => $result['data']['pending'] ?? [],
                'waived' => $result['data']['waived'] ?? [],
                'handovers' => $result['data']['handovers'] ?? [],
                'roles' => $result['data']['roles'] ?? [],
                'receivers' => (int) ($result['data']['receivers'] ?? 0),
                'accounts' => $result['data']['accounts'] ?? [],
                'activation_days' => (int) ($result['meta']['activation_days'] ?? 14),
            ])->values()->all(),
            'hosting' => [
                'ready' => $this->mailboxes->refusal() === null,
                'refusal' => $this->mailboxes->refusal(),
                'domain' => ProfessionalEmailAddress::configured() ? ProfessionalEmailAddress::domain() : null,
            ],
            'can' => [
                'create' => $user->can('staff_access.create') && $user->can('professional_emails.create'),
                // ADR-199 — confier la remise à un compte du site.
                'designate' => $user->can('staff_access.create') && $user->can('permissions.assign'),
            ],
            'filters' => ['site' => is_string($request->query('site')) ? mb_strtoupper($request->query('site')) : null],
        ]);
    }

    /** Un accès, créé ou refusé : l'écran les enchaîne un par un et montre l'avancement. */
    public function grant(Request $request, string $site): JsonResponse
    {
        $this->assertSite($site);
        $validated = $request->validate([
            'handover_uuid' => ['required', 'uuid'],
            'employee_uuid' => ['required', 'uuid'],
            'local_part' => ['nullable', 'string', 'max:64', 'regex:'.ProfessionalEmailAddress::LOCAL_PART_PATTERN],
            'role_id' => ['required', 'integer', 'min:1'],
            'professional_profile_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'local_part.regex' => ProfessionalEmailAddress::LOCAL_PART_MESSAGE,
            'role_id.required' => 'Choisissez le rôle du compte.',
        ]);

        $result = $this->provisioner->grant(mb_strtoupper($site), $validated, $request->user());

        return response()->json(collect($result)->except(['ok', 'status'])->all(), $result['status']);
    }

    public function send(Request $request, string $site, string $handover): JsonResponse
    {
        $this->assertSite($site);
        $result = $this->sites->sendStaffAccessHandover(mb_strtoupper($site), $handover, $request->user());

        return response()->json([
            'message' => $result['message'] ?? ($result['ok'] ? 'Accès envoyés au RH.' : 'Le site a refusé l’envoi.'),
            'handover' => $result['data'] ?? null,
            'errors' => $result['errors'] ?? [],
        ], $result['ok'] ? 200 : (int) ($result['http_status'] ?? 422));
    }

    /** ADR-199 — personne au site ne peut recevoir : le Super Admin désigne qui remettra les accès. */
    public function designate(Request $request, string $site): JsonResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(
            ['user_uuid' => ['required', 'uuid']],
            ['user_uuid.required' => 'Choisissez le compte qui remettra les accès.'],
        );
        $result = $this->sites->designateStaffAccessReceiver(mb_strtoupper($site), $validated['user_uuid'], $request->user());

        return response()->json([
            'message' => $result['message'] ?? ($result['ok'] ? 'Compte désigné.' : 'Le site a refusé.'),
            'receivers' => $result['data']['receivers'] ?? null,
            'accounts' => $result['data']['accounts'] ?? null,
            'errors' => $result['errors'] ?? [],
        ], $result['ok'] ? 200 : (int) ($result['http_status'] ?? 422));
    }

    public function waive(Request $request, string $site, string $employee): JsonResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Dites pourquoi cet employé n’a pas besoin d’accès.', 'reason.min' => 'Dites pourquoi cet employé n’a pas besoin d’accès.'],
        );

        return $this->relay($this->sites->staffAccessWaiver(mb_strtoupper($site), $employee, 'waive', $validated, $request->user()));
    }

    public function unwaive(Request $request, string $site, string $employee): JsonResponse
    {
        $this->assertSite($site);

        return $this->relay($this->sites->staffAccessWaiver(mb_strtoupper($site), $employee, 'unwaive', [], $request->user()));
    }

    /** @param array<string, mixed> $result */
    private function relay(array $result): JsonResponse
    {
        return response()->json([
            'message' => $result['message'] ?? null,
            'errors' => $result['errors'] ?? [],
        ], $result['ok'] ? 200 : (int) ($result['http_status'] ?? 422));
    }
}
