<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\StaffAccess\DesignateStaffAccessReceiverAction;
use App\Actions\StaffAccess\GrantStaffAccessAction;
use App\Actions\StaffAccess\SendStaffAccessHandoverAction;
use App\Actions\StaffAccess\WaiveStaffAccessAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ProfessionalProfile;
use App\Models\StaffAccessHandover;
use App\Models\User;
use App\Services\Auth\AccountActivation;
use App\Services\Catalog\CatalogActor;
use App\Services\StaffAccess\StaffAccessDirectory;
use App\Support\ProfessionalEmailAddress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-197 — l'accès du personnel, vu et créé depuis le portail, par l'API du site.
 *
 * Le portail crée la boîte chez l'hébergeur ; le site crée le compte, sans mot
 * de passe (l'employé le choisit à sa première connexion, ADR-202), et le relie à
 * la fiche. Chaque droit est revérifié ici, sur l'acteur distant.
 */
class StaffAccessController extends Controller
{
    public function __construct(private readonly StaffAccessDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.view');

        $pending = $this->directory->pending();

        return response()->json([
            'data' => [
                'pending' => $pending,
                'waived' => $this->directory->waived(),
                'handovers' => $this->directory->handovers(),
                'roles' => $this->directory->roles(),
                'receivers' => SendStaffAccessHandoverAction::recipients()->count(),
                // ADR-199 — les comptes du site à qui confier la remise, quand personne ne le peut encore.
                'accounts' => $actor->can('permissions.assign') ? DesignateStaffAccessReceiverAction::candidates() : [],
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'domain' => ProfessionalEmailAddress::configured() ? ProfessionalEmailAddress::domain() : null,
                'summary' => ['pending' => count($pending)],
                // ADR-202 — le délai de la première connexion sur ce site.
                'activation_days' => AccountActivation::days(),
            ],
        ]);
    }

    /** L'état d'un employé, relu par le portail juste avant de créer son accès. */
    public function employee(Request $request, string $employeeUuid): JsonResponse
    {
        $this->authorizeActor($request, 'staff_access.create');

        return response()->json(['data' => $this->directory->state($this->findEmployee($employeeUuid))]);
    }

    public function grant(Request $request, GrantStaffAccessAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.create');
        foreach (['users.create', 'roles.assign'] as $permission) {
            if ($actor->cannot($permission)) {
                throw new AuthorizationException('Créer le compte demande le droit « '.$permission.' ».');
            }
        }

        $dryRun = $request->boolean('dry_run');
        $validated = $request->validate([
            'handover_uuid' => ['required', 'uuid'],
            'employee_uuid' => ['required', 'uuid'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            // ADR-202 — aucun mot de passe ne voyage : l'employé choisit le sien à sa première connexion.
            'password' => ['prohibited'],
            'mailbox_address' => ['nullable', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('deleted_at')->whereNot('code', 'SUPER_ADMIN')],
            'professional_profile_id' => [
                Rule::requiredIf(fn () => ProfessionalProfile::query()->active()->where('role_id', (int) $request->input('role_id'))->exists()),
                'nullable',
                'integer',
                Rule::exists('professional_profiles', 'id')->where(fn ($query) => $query->where('role_id', (int) $request->input('role_id'))->where('active', true)),
            ],
        ], [
            'email.unique' => 'Cette adresse sert déjà à un autre compte RIVO.',
            'role_id.required' => 'Choisissez le rôle du compte.',
            'professional_profile_id.required' => 'Ce rôle a des profils métier : choisissez-en un.',
            'password.prohibited' => 'Aucun mot de passe n’est transmis : l’employé choisit le sien à sa première connexion.',
        ]);

        $employee = $this->findEmployee($validated['employee_uuid']);
        GrantStaffAccessAction::assertEligible($employee);

        // Vérification seule : le portail s'assure que tout passera avant de créer la boîte.
        if ($dryRun) {
            return response()->json(['message' => 'Prêt à créer.']);
        }

        $result = $action->execute($employee, $validated, $actor);

        return response()->json([
            'message' => "Accès de {$result['user']->name} créé.",
            'data' => [
                'user' => ['uuid' => $result['user']->uuid, 'name' => $result['user']->name, 'email' => $result['user']->email],
                'handover' => $this->directory->handover($result['handover']->fresh('items')),
            ],
        ], 201);
    }

    public function send(Request $request, string $handoverUuid, SendStaffAccessHandoverAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.create');
        $handover = StaffAccessHandover::query()->where('uuid', $handoverUuid)->firstOrFail();
        $result = $action->execute($handover, $actor);

        return response()->json([
            'message' => $result['already']
                ? 'Cette remise était déjà partie au RH.'
                : 'Accès envoyés au RH : '.$result['recipients'].' compte'.($result['recipients'] > 1 ? 's' : '').' prévenu'.($result['recipients'] > 1 ? 's' : '').'.',
            'data' => $this->directory->handover($handover->fresh('items')),
        ]);
    }

    /**
     * ADR-199 — confier la remise à un compte du site : il reçoit le droit
     * `staff_access.receive`, en exception individuelle auditée.
     */
    public function designateReceiver(Request $request, DesignateStaffAccessReceiverAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.create');
        if ($actor->cannot('permissions.assign')) {
            throw new AuthorizationException('Confier la remise demande le droit « permissions.assign ».');
        }

        $validated = $request->validate(
            ['user_uuid' => ['required', 'uuid']],
            ['user_uuid.required' => 'Choisissez le compte qui remettra les accès.'],
        );
        $user = User::query()->where('uuid', $validated['user_uuid'])->firstOrFail();
        $action->execute($user, $actor);

        return response()->json([
            'message' => "{$user->name} recevra et remettra les accès du personnel.",
            'data' => [
                'receivers' => SendStaffAccessHandoverAction::recipients()->count(),
                'accounts' => DesignateStaffAccessReceiverAction::candidates(),
            ],
        ]);
    }

    public function waive(Request $request, string $employeeUuid, WaiveStaffAccessAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.create');
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Dites pourquoi cet employé n’a pas besoin d’accès.', 'reason.min' => 'Dites pourquoi cet employé n’a pas besoin d’accès.'],
        );
        $employee = $this->findEmployee($employeeUuid);
        $action->waive($employee, trim($validated['reason']), $actor);

        return response()->json(['message' => "{$employee->first_name} {$employee->last_name} : aucun accès nécessaire."]);
    }

    public function unwaive(Request $request, string $employeeUuid, WaiveStaffAccessAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'staff_access.create');
        $employee = $this->findEmployee($employeeUuid);
        $action->restore($employee, $actor);

        return response()->json(['message' => "{$employee->first_name} {$employee->last_name} attend de nouveau son accès."]);
    }

    private function findEmployee(string $uuid): Employee
    {
        return Employee::withTrashed()->where('uuid', $uuid)->firstOrFail();
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante demande le droit « '.$permission.' ».');
        }

        return $actor;
    }
}
