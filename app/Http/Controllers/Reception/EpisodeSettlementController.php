<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordAdministrativeExitRequest;
use App\Models\Episode;
use App\Services\Reception\EpisodeAccountControl;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réception's settlement board: the passages the doctor has finished with
 * and which are now waiting on an administrative decision (CDC §33.3).
 *
 * Read-only for the clinical side — nothing here touches a diagnosis, a
 * prescription or an orientation (§34.2 rule 1). It shows the account
 * control of §33.2 and records the exit; collecting the money itself stays
 * where it belongs, at the Caisse (ADR-012).
 */
class EpisodeSettlementController extends Controller
{
    public function __construct(private readonly EpisodeAccountControl $accounts) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewAccounts = $user->can('billing.view');

        $tab = in_array($request->query('tab'), ['pending', 'discharged'], true)
            ? $request->query('tab')
            : 'pending';
        $search = trim((string) $request->query('q', ''));

        $episodes = Episode::query()
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name,phone,deleted_at',
                'medicalDischarge:id,episode_id,type,discharged_at',
                'administrativeExitAuthor:id,name',
            ])
            ->when($tab === 'pending', fn (Builder $query) => $query
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value))
            ->when($tab === 'discharged', fn (Builder $query) => $query
                ->whereIn('administrative_status', [
                    EpisodeAdministrativeStatus::DischargedPaid->value,
                    EpisodeAdministrativeStatus::DischargedDebt->value,
                    EpisodeAdministrativeStatus::DischargedEscaped->value,
                    // Legacy bucket: nothing writes it, but a row carrying
                    // it must still be findable rather than vanish.
                    EpisodeAdministrativeStatus::Discharged->value,
                ]))
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn (Builder $patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))))
            ->orderByRaw($tab === 'pending'
                // Waiting longest first: that patient is standing at the desk.
                ? 'COALESCE(started_at, created_at) ASC'
                : 'COALESCE(administrative_exit_at, ended_at, updated_at) DESC')
            ->paginate(20)
            ->withQueryString();

        $summaries = $canViewAccounts
            ? $this->accounts->summarizeMany($episodes->pluck('id')->all())
            : [];

        return Inertia::render('Reception/Settlements/Index', [
            'tab' => $tab,
            'filters' => ['q' => $search],
            'episodes' => [
                'data' => $episodes->getCollection()->map(fn (Episode $episode) => [
                    'uuid' => $episode->uuid,
                    'episode_number' => $episode->episode_number,
                    'priority' => $episode->priority->value,
                    'status' => $episode->status->value,
                    'administrative_status' => $episode->administrative_status?->value,
                    'administrative_status_label' => $episode->administrative_status?->label(),
                    'medical_status' => $episode->medical_status?->value,
                    'medical_status_label' => $episode->medical_status?->label(),
                    'started_at' => $episode->started_at,
                    'medical_discharge' => $episode->medicalDischarge ? [
                        'type' => $episode->medicalDischarge->type->value,
                        'type_label' => $episode->medicalDischarge->type->label(),
                        'discharged_at' => $episode->medicalDischarge->discharged_at,
                    ] : null,
                    'administrative_exit' => $episode->administrative_exit_type ? [
                        'type' => $episode->administrative_exit_type->value,
                        'type_label' => $episode->administrative_exit_type->label(),
                        'exited_at' => $episode->administrative_exit_at,
                        'balance_amount' => $episode->administrative_exit_balance,
                        'reason' => $episode->administrative_exit_reason,
                        'author' => $episode->administrativeExitAuthor?->name,
                    ] : null,
                    // Amounts belong to billing.view, not to the route's own
                    // permission — same section-by-section guarding as the
                    // passage detail page (ADR-054).
                    'account' => $summaries[$episode->id] ?? null,
                    'patient' => $episode->patient ? [
                        'uuid' => $episode->patient->uuid,
                        'patient_number' => $episode->patient->patient_number,
                        'first_name' => $episode->patient->first_name,
                        'last_name' => $episode->patient->last_name,
                        'phone' => $episode->patient->phone,
                        'deleted_at' => $episode->patient->deleted_at,
                    ] : null,
                ])->values(),
                'links' => $episodes->linkCollection(),
                'meta' => [
                    'current_page' => $episodes->currentPage(),
                    'last_page' => $episodes->lastPage(),
                    'total' => $episodes->total(),
                    'from' => $episodes->firstItem(),
                    'to' => $episodes->lastItem(),
                ],
            ],
            'counts' => [
                'pending' => Episode::query()
                    ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value)
                    ->count(),
            ],
            'exitTypes' => array_map(fn (AdministrativeExitType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'creates_debt' => $type->createsDebt(),
            ], AdministrativeExitType::cases()),
            'capabilities' => [
                'can_view_accounts' => $canViewAccounts,
                'can_record_exit' => $user->can('episodes.administrative_exit'),
                'can_authorize_debt' => $user->can('debts.authorize'),
                'can_collect' => $user->can('payments.create'),
                'can_view_cash' => $user->can('cash.view'),
                'can_view_patients' => $user->can('patients.view'),
            ],
        ]);
    }

    public function store(
        RecordAdministrativeExitRequest $request,
        Episode $episode,
        RecordAdministrativeExitAction $action,
    ): RedirectResponse {
        $episode = $action->execute($episode, $request->validated(), $request->user());

        return back()->with(
            'status',
            "Passage {$episode->episode_number} — {$episode->administrative_exit_type->label()}.",
        );
    }
}
