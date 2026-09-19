<?php

namespace App\Http\Controllers;

use App\Actions\Pediatrics\AcceptPediatricsOrientationAction;
use App\Actions\Pediatrics\DischargePediatricsOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\MedicalDischargeType;
use App\Http\Requests\Pediatrics\DischargePediatricsRequest;
use App\Models\ConsultationOrientation;
use App\Models\EpisodeOrientation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-114 — l'espace Pédiatrie, volontairement simple.
 *
 * Une file des patients orientés par Médecine, leur prise en charge, puis la
 * sortie médicale. Aucune fiche pédiatrique n'est inventée tant que la
 * clinique n'en a pas fourni une. Cet espace n'encaisse rien.
 */
class PediatricsController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), ['active', 'completed'], true)
            ? $request->query('filter')
            : 'active';

        $base = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Pediatrics->value)
            ->whereHas('episode.patient');

        $active = [EpisodeOrientationStatus::Pending->value, EpisodeOrientationStatus::InProgress->value];

        $counts = [
            'active' => (clone $base)->whereIn('status', $active)->count(),
            'completed' => (clone $base)->where('status', EpisodeOrientationStatus::Completed->value)->count(),
        ];

        $orientations = $base
            ->when($filter === 'active', fn ($query) => $query->whereIn('status', $active), fn ($query) => $query->where('status', EpisodeOrientationStatus::Completed->value))
            ->with(['episode.patient', 'acceptedBy:id,name'])
            ->when($search !== '', fn ($query) => $query->whereHas('episode', fn ($episode) => $episode
                ->where('episode_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($patient) => $patient
                    ->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderBy($filter === 'active' ? 'oriented_at' : 'completed_at', $filter === 'active' ? 'asc' : 'desc')
            ->paginate(20)
            ->withQueryString();

        $orientations->through(fn (EpisodeOrientation $orientation): array => [
            'uuid' => $orientation->uuid,
            'status' => $orientation->status->value,
            'status_label' => $orientation->status->label(),
            'episode_number' => $orientation->episode->episode_number,
            'priority' => $orientation->episode->priority->value,
            'patient' => $this->patient($orientation),
            'reason' => $orientation->reason,
            'oriented_at' => $orientation->oriented_at,
            'accepted_by' => $orientation->acceptedBy?->name,
            'completed_at' => $orientation->completed_at,
        ]);

        return Inertia::render('Pediatrics/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function show(Request $request, EpisodeOrientation $episodeOrientation): Response
    {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Pediatrics, 404);

        $episodeOrientation->load([
            'episode.patient.allergies',
            'episode.medicalDischarge.creator:id,name',
            'acceptedBy:id,name',
            'completedBy:id,name',
        ]);

        $user = $request->user();
        $episode = $episodeOrientation->episode;
        $open = $episode->status === EpisodeStatus::Open;
        $source = ConsultationOrientation::query()
            ->with(['consultation.doctor:id,name', 'consultation.diagnoses' => fn ($query) => $query->whereDoesntHave('cancellation')])
            ->where('episode_orientation_id', $episodeOrientation->getKey())
            ->first();
        $discharge = $episode->medicalDischarge;

        return Inertia::render('Pediatrics/Show', [
            'orientation' => [
                'uuid' => $episodeOrientation->uuid,
                'status' => $episodeOrientation->status->value,
                'status_label' => $episodeOrientation->status->label(),
                'reason' => $episodeOrientation->reason,
                'oriented_at' => $episodeOrientation->oriented_at,
                'accepted_at' => $episodeOrientation->accepted_at,
                'accepted_by' => $episodeOrientation->acceptedBy?->name,
                'completed_at' => $episodeOrientation->completed_at,
                'completed_by' => $episodeOrientation->completedBy?->name,
                'requested_by' => $source?->consultation?->doctor?->name,
                'diagnoses' => $source?->consultation?->diagnoses
                    ->map(fn ($diagnosis) => $diagnosis->description)
                    ->filter()
                    ->values() ?? [],
            ],
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'url' => "/passages/{$episode->uuid}",
            ],
            'patient' => $this->patient($episodeOrientation),
            'allergies' => $episode->patient->allergies->map(fn ($allergy) => $allergy->substance)->filter()->values(),
            'discharge' => $discharge ? [
                'type_label' => $discharge->type->label(),
                'final_diagnosis' => $discharge->final_diagnosis,
                'patient_condition' => $discharge->patient_condition,
                'discharged_at' => $discharge->discharged_at,
                'discharged_by' => $discharge->creator?->name,
            ] : null,
            'dischargeTypes' => collect(MedicalDischargeType::cases())
                ->map(fn (MedicalDischargeType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'capabilities' => [
                'can_accept' => $open && $episodeOrientation->status === EpisodeOrientationStatus::Pending
                    && $user->can('pediatrics.manage'),
                'can_discharge' => $open && $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $discharge === null
                    && $user->can('pediatrics.manage')
                    && $user->can('medical_discharge.create'),
            ],
        ]);
    }

    public function accept(Request $request, EpisodeOrientation $episodeOrientation, AcceptPediatricsOrientationAction $action): RedirectResponse
    {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('pediatrics.show', $episodeOrientation)->with('status', 'Prise en charge Pédiatrie commencée.');
    }

    public function discharge(
        DischargePediatricsRequest $request,
        EpisodeOrientation $episodeOrientation,
        DischargePediatricsOrientationAction $action,
    ): RedirectResponse {
        $discharge = $action->execute($episodeOrientation, $request->validated(), $request->user());

        if ($discharge->type === MedicalDischargeType::Deceased && $request->user()->can('death_records.view')) {
            return redirect()->route('deaths.index')
                ->with('status', 'Décès prononcé. Établissez l’acte de constatation.');
        }

        return redirect()->route('pediatrics.show', $episodeOrientation)
            ->with('status', 'Sortie médicale prononcée. Le passage rejoint « Sorties & règlements ».');
    }

    /** @return array<string, mixed> */
    private function patient(EpisodeOrientation $orientation): array
    {
        $patient = $orientation->episode->patient;

        return [
            'uuid' => $patient?->uuid,
            'patient_number' => $patient?->patient_number,
            'name' => trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
            'sex' => $patient?->sex?->value,
            'age' => $patient?->birth_date?->age ?? $patient?->declared_age,
        ];
    }
}
