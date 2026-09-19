<?php

namespace App\Http\Controllers;

use App\Actions\Transfer\ConfirmTransferDepartureAction;
use App\Actions\Transfer\UpdateMedicalReferralAction;
use App\Enums\ClinicalPriority;
use App\Enums\EpisodeStatus;
use App\Enums\MedicalRequestStatus;
use App\Http\Requests\Transfer\ConfirmTransferDepartureRequest;
use App\Http\Requests\Transfer\UpdateMedicalReferralRequest;
use App\Models\MedicalReferral;
use App\Support\MedicalReferralDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-114 — l'espace Transferts.
 *
 * Il réunit les patients que le médecin a référés vers un autre
 * établissement ou un autre site. La demande part en un clic depuis la
 * consultation ; l'établissement et le résumé se complètent ici, puis
 * « Transfert effectué » constate le départ. Jusque-là le patient reste en
 * soins. Cet espace n'encaisse rien.
 */
class TransferController extends Controller
{
    public function __construct(private readonly MedicalReferralDocument $document) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), ['pending', 'departed'], true)
            ? $request->query('filter')
            : 'pending';

        $base = MedicalReferral::query()->where('status', MedicalRequestStatus::Requested->value);

        $counts = [
            'pending' => (clone $base)->whereNull('departed_at')->count(),
            'departed' => (clone $base)->whereNotNull('departed_at')->count(),
        ];

        $referrals = $base
            ->when($filter === 'pending', fn ($query) => $query->whereNull('departed_at'), fn ($query) => $query->whereNotNull('departed_at'))
            ->with([
                'episode.patient:id,uuid,patient_number,first_name,last_name,sex,birth_date,declared_age',
                'referredBy:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('facility', 'like', "%{$search}%")
                ->orWhereHas('episode', fn ($episode) => $episode
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")))))
            ->orderByDesc($filter === 'pending' ? 'referred_at' : 'departed_at')
            ->paginate(20)
            ->withQueryString();

        $referrals->through(fn (MedicalReferral $referral): array => [
            'uuid' => $referral->uuid,
            'episode_number' => $referral->episode->episode_number,
            'patient' => $this->patient($referral),
            'facility' => $referral->facility,
            'reason' => $this->document->plain($referral->reason),
            'priority' => $referral->priority?->value,
            'referred_by' => $referral->referredBy?->name,
            'referred_at' => $referral->referred_at,
            'departed_at' => $referral->departed_at,
        ]);

        return Inertia::render('Transfers/Index', [
            'referrals' => $referrals,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function show(Request $request, MedicalReferral $medicalReferral): Response
    {
        $user = $request->user();
        $open = $medicalReferral->status === MedicalRequestStatus::Requested
            && ! $medicalReferral->hasDeparted()
            && $medicalReferral->episode->status === EpisodeStatus::Open;

        return Inertia::render('Transfers/Show', [
            'referral' => $this->referral($medicalReferral),
            'priorities' => collect(ClinicalPriority::cases())
                ->map(fn (ClinicalPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()])
                ->values(),
            'destinations' => $this->destinations(),
            'capabilities' => [
                'can_manage' => $open && $user->can('transfers.manage'),
            ],
        ]);
    }

    public function update(
        UpdateMedicalReferralRequest $request,
        MedicalReferral $medicalReferral,
        UpdateMedicalReferralAction $action,
    ): RedirectResponse {
        $action->execute($medicalReferral, $request->validated());

        return back()->with('status', 'Demande de transfert complétée.');
    }

    public function depart(
        ConfirmTransferDepartureRequest $request,
        MedicalReferral $medicalReferral,
        ConfirmTransferDepartureAction $action,
    ): RedirectResponse {
        $action->execute($medicalReferral, $request->validated(), $request->user());

        return redirect()->route('transfers.show', $medicalReferral)
            ->with('status', 'Transfert effectué. Le passage rejoint « Sorties & règlements ».');
    }

    public function print(MedicalReferral $medicalReferral): Response
    {
        $medicalReferral->load(['referredBy:id,name', 'episode.patient', 'consultation.orientation']);
        $episode = $medicalReferral->episode;
        $patient = $episode->patient;

        return Inertia::render('Medicine/MedicalReferralPrint', [
            'orientation' => ['uuid' => $medicalReferral->consultation?->orientation?->uuid],
            'backHref' => route('transfers.show', $medicalReferral, false),
            'episode' => [
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
            ],
            'patient' => [
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
            ],
            'referral' => [
                'uuid' => $medicalReferral->uuid,
                ...$this->document->present($medicalReferral),
                'priority' => $medicalReferral->priority->value,
                'priority_label' => $medicalReferral->priority->label(),
                'status' => $medicalReferral->status->value,
                'status_label' => $medicalReferral->status->label(),
                'referred_at' => $medicalReferral->referred_at,
                'referred_by' => $medicalReferral->referredBy?->name,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function referral(MedicalReferral $referral): array
    {
        $referral->load(['episode.patient', 'referredBy:id,name', 'departedBy:id,name', 'cancelledBy:id,name']);

        return [
            'uuid' => $referral->uuid,
            'status' => $referral->status->value,
            'status_label' => $referral->status->label(),
            'departed' => $referral->hasDeparted(),
            'episode' => [
                'uuid' => $referral->episode->uuid,
                'episode_number' => $referral->episode->episode_number,
                'url' => "/passages/{$referral->episode->uuid}",
                'medical_status_label' => $referral->episode->medical_status?->label(),
            ],
            'patient' => $this->patient($referral),
            ...$this->document->present($referral),
            'priority' => $referral->priority->value,
            'priority_label' => $referral->priority->label(),
            'referred_by' => $referral->referredBy?->name,
            'referred_at' => $referral->referred_at,
            'departed_at' => $referral->departed_at,
            'departed_by' => $referral->departedBy?->name,
            'departure_notes' => $referral->departure_notes,
            'cancelled_at' => $referral->cancelled_at,
            'cancelled_by' => $referral->cancelledBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function patient(MedicalReferral $referral): array
    {
        $patient = $referral->episode->patient;

        return [
            'uuid' => $patient?->uuid,
            'patient_number' => $patient?->patient_number,
            'name' => trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
            'age' => $patient?->birth_date?->age ?? $patient?->declared_age,
        ];
    }

    /** Les autres sites de la clinique, proposés comme destinations. */
    private function destinations(): array
    {
        return collect(config('rivo.clinics', []))
            ->filter(fn (array $site) => strtoupper((string) ($site['code'] ?? '')) !== strtoupper((string) config('rivo.site.code')))
            ->map(fn (array $site) => 'Clinique Saint Georges — '.$site['name'])
            ->values()
            ->all();
    }
}
