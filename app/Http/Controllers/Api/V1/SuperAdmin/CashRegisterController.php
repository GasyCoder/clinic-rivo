<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Cash\CloseCashSessionAction;
use App\Actions\Cash\LockCashSessionAction;
use App\Actions\Cash\UnlockCashSessionAction;
use App\Enums\CashSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Services\Cash\CashRegisterManager;
use App\Services\Cash\CashRegisterProfileService;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.view');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
        ]);
        $status = $validated['status'] ?? 'ACTIVE';
        $query = CashRegister::query()
            ->withCount('sessions')
            ->with(['activeSession.opener:id,name', 'activeSession.locker:id,name', 'acceptedPaymentMethods']);

        if ($status !== 'ACTIVE') {
            $query->withTrashed();
        }

        if ($status === 'ARCHIVED') {
            $query->onlyTrashed();
        }

        if (filled($validated['search'] ?? null)) {
            $normalized = CashRegister::normalize($validated['search']);
            $query->where('normalized_name', 'like', '%'.$normalized.'%');
        }

        $registers = $query->orderBy('normalized_name')->get();

        return response()->json([
            'data' => $registers->map(fn (CashRegister $register) => $this->serialize($register))->values(),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => [
                    'displayed' => $registers->count(),
                    'active' => CashRegister::query()->count(),
                    'archived' => CashRegister::onlyTrashed()->count(),
                ],
                'payment_methods' => PaymentMethod::query()
                    ->where('active', true)
                    ->orderBy('name')
                    ->get(['uuid', 'code', 'name', 'category'])
                    ->map(fn (PaymentMethod $method) => [
                        'uuid' => $method->uuid,
                        'code' => $method->code,
                        'name' => $method->name,
                        'category_label' => $method->category->label(),
                    ])->values(),
            ],
        ]);
    }

    public function show(
        Request $request,
        string $cashRegisterUuid,
        CashRegisterProfileService $profiles,
    ): JsonResponse {
        $this->authorizeActor($request, 'cash_registers.view');
        $register = CashRegister::withTrashed()->where('uuid', $cashRegisterUuid)->firstOrFail();

        return response()->json([
            'data' => $profiles->build($register),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
            ],
        ]);
    }

    public function store(Request $request, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.create');
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $register = $manager->create($validated['name']);

        return response()->json([
            'message' => 'Caisse ajoutée au référentiel du site.',
            'data' => $this->serialize($register),
        ], 201);
    }

    public function update(Request $request, string $cashRegisterUuid, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.update');
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $register = $manager->update($register, $validated['name']);

        return response()->json([
            'message' => 'Caisse mise à jour.',
            'data' => $this->serialize($register),
        ]);
    }

    public function activate(Request $request, string $cashRegisterUuid, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.activate');
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $register = $manager->activate($register);

        return response()->json([
            'message' => 'Caisse activée.',
            'data' => $this->serialize($register),
        ]);
    }

    /**
     * Tenders this desk accepts. An empty list lifts the restriction: every
     * active tender of the site becomes acceptable again.
     */
    public function updatePaymentMethods(
        Request $request,
        string $cashRegisterUuid,
        CashRegisterManager $manager,
    ): JsonResponse {
        $actor = $this->authorizeActor($request, 'cash_registers.update');
        $validated = $request->validate([
            'payment_method_uuids' => ['present', 'array', 'max:50'],
            'payment_method_uuids.*' => ['uuid'],
        ]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $register = $manager->syncAcceptedPaymentMethods(
            $register,
            $validated['payment_method_uuids'],
            $actor->user(),
        );

        return response()->json([
            'message' => $register->acceptedPaymentMethods->isEmpty()
                ? 'Cette caisse accepte désormais tous les modes de paiement actifs du site.'
                : 'Modes de paiement de la caisse mis à jour.',
            'data' => $this->serialize($register),
        ]);
    }

    public function deactivate(Request $request, string $cashRegisterUuid, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.deactivate');
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $register = $manager->deactivate($register);

        return response()->json([
            'message' => 'Caisse désactivée.',
            'data' => $this->serialize($register),
        ]);
    }

    public function destroy(Request $request, string $cashRegisterUuid, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'cash_registers.archive');
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $manager->archive($register, $validated['reason']);

        return response()->json(['message' => 'Caisse archivée.']);
    }

    public function restore(Request $request, string $cashRegisterUuid, CashRegisterManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'trash.restore');
        $this->authorizeActor($request, 'cash_registers.restore');
        $register = CashRegister::onlyTrashed()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $register = $manager->restore($register);

        return response()->json([
            'message' => 'Caisse restaurée.',
            'data' => $this->serialize($register),
        ]);
    }

    public function lock(
        Request $request,
        string $cashRegisterUuid,
        LockCashSessionAction $action,
    ): JsonResponse {
        $actor = $this->authorizeActor($request, 'cash_registers.lock');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $session = $action->execute($register, str($validated['reason'])->squish()->toString(), $actor);

        return response()->json([
            'message' => "Session {$session->session_number} verrouillée. Aucun encaissement n’est possible tant qu’elle n’est pas déverrouillée.",
        ]);
    }

    public function unlock(
        Request $request,
        string $cashRegisterUuid,
        UnlockCashSessionAction $action,
    ): JsonResponse {
        $actor = $this->authorizeActor($request, 'cash_registers.unlock');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $session = $action->execute($register, str($validated['reason'])->squish()->toString(), $actor);

        return response()->json([
            'message' => "Session {$session->session_number} déverrouillée. Les encaissements peuvent reprendre.",
        ]);
    }

    public function close(
        Request $request,
        string $cashRegisterUuid,
        CloseCashSessionAction $action,
    ): JsonResponse {
        $actor = $this->authorizeActor($request, 'cash_registers.close');
        $validated = $request->validate([
            'actual_closing_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->firstOrFail();
        $reason = str($validated['reason'])->squish()->toString();
        $session = $action->execute(
            (string) $validated['actual_closing_amount'],
            null,
            $actor,
            $reason,
            $register,
        );

        return response()->json([
            'message' => "Session {$session->session_number} clôturée par la Super Administration.",
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(CashRegister $register): array
    {
        $register->loadMissing([
            'activeSession.opener:id,name', 'activeSession.locker:id,name', 'acceptedPaymentMethods',
        ]);
        $session = $register->activeSession;
        $sessionStatus = $session?->status instanceof CashSessionStatus
            ? $session->status->value
            : ($session?->status ? (string) $session->status : null);

        return [
            'uuid' => $register->uuid,
            'name' => $register->name,
            // Independent from archiving: a deactivated-but-not-archived
            // register simply drops out of the site's own /cash picker
            // without touching its soft-delete/history state.
            'active' => (bool) $register->active,
            'archived' => $register->trashed(),
            'sessions_count' => (int) ($register->sessions_count ?? 0),
            // Empty means no restriction: every active tender is accepted.
            'accepted_payment_methods' => $register->acceptedPaymentMethods
                ->map(fn ($method) => [
                    'uuid' => $method->uuid,
                    'code' => $method->code,
                    'name' => $method->name,
                    'category_label' => $method->category->label(),
                ])->values(),
            'session' => $session ? [
                'uuid' => $session->uuid,
                'session_number' => $session->session_number,
                'status' => $sessionStatus,
                'opened_by' => $session->opener?->name,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'locked_by' => $session->locker?->name ?? $session->external_locked_by_name,
                'locked_at' => $session->locked_at?->toIso8601String(),
            ] : null,
            'archived_at' => $register->deleted_at?->toIso8601String(),
            'archive_reason' => $register->delete_reason,
            'updated_at' => $register->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
