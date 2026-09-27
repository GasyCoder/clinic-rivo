<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Services\Administration\ProfessionalMailboxDirectory;
use App\Services\Administration\ProfessionalMailboxPresenter;
use App\Services\Administration\ProfessionalMailboxWorkflow;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-190 — les adresses email professionnelles d'un site, lues et tenues à
 * jour par le portail. Le portail agit chez l'hébergeur puis le dit ici ; le
 * site revérifie chaque droit et chaque transition, et signe l'audit du nom
 * du Super Admin.
 */
class ProfessionalMailboxController extends Controller
{
    public function __construct(
        private readonly ProfessionalMailboxWorkflow $workflow,
        private readonly ProfessionalMailboxPresenter $presenter,
    ) {}

    public function index(Request $request, ProfessionalMailboxDirectory $directory): JsonResponse
    {
        $this->authorizeActor($request, 'professional_emails.view');

        return response()->json($directory->listing(CatalogActor::fromRemoteRequest($request)->can('professional_emails.request')));
    }

    /** Le portail demande lui-même l'adresse (« Nouvelle adresse »), avant de la créer chez l'hébergeur. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_uuid' => ['required', 'uuid'],
            'local_part' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $employee = Employee::query()->where('uuid', $validated['employee_uuid'])->firstOrFail();
        $mailbox = $this->workflow->request($employee, $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Demande enregistrée : {$mailbox->address}.", 'data' => $this->presenter->present($mailbox)], 201);
    }

    public function show(Request $request, string $mailboxUuid): JsonResponse
    {
        $this->authorizeActor($request, 'professional_emails.view');

        return response()->json(['data' => $this->presenter->present($this->mailbox($mailboxUuid))]);
    }

    public function activate(Request $request, string $mailboxUuid): JsonResponse
    {
        $validated = $request->validate(['address' => ['required', 'string', 'max:254']]);
        $mailbox = $this->workflow->activate($this->mailbox($mailboxUuid), $validated['address'], CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Adresse {$mailbox->address} active ; elle est désormais l’email de la fiche employé.",
            'data' => $this->presenter->present($mailbox),
        ]);
    }

    public function reject(Request $request, string $mailboxUuid): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $mailbox = $this->workflow->reject($this->mailbox($mailboxUuid), $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Demande refusée.', 'data' => $this->presenter->present($mailbox)]);
    }

    public function suspend(Request $request, string $mailboxUuid): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $mailbox = $this->workflow->suspend($this->mailbox($mailboxUuid), $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Adresse {$mailbox->address} suspendue.", 'data' => $this->presenter->present($mailbox)]);
    }

    public function reactivate(Request $request, string $mailboxUuid): JsonResponse
    {
        $mailbox = $this->workflow->reactivate($this->mailbox($mailboxUuid), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Adresse {$mailbox->address} réactivée.", 'data' => $this->presenter->present($mailbox)]);
    }

    private function mailbox(string $uuid): ProfessionalMailbox
    {
        return ProfessionalMailbox::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function authorizeActor(Request $request, string $permission): void
    {
        if (CatalogActor::fromRemoteRequest($request)->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
