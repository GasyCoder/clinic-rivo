<?php

namespace App\Http\Controllers;

use App\Actions\Reception\RegisterArrivalAction;
use App\Enums\EpisodePriority;
use App\Exceptions\DuplicatePatientException;
use App\Http\Requests\StoreArrivalRequest;
use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CDC §5.2.1 "Réception" — the single working screen for the front desk:
 * search-or-create a patient, then always create their passage. See
 * RegisterArrivalAction for why these aren't separate actions.
 */
class ReceptionController extends Controller
{
    public function create(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $matches = $search !== ''
            ? Patient::query()
                ->where(function ($query) use ($search) {
                    $query->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->limit(10)
                ->get()
                ->map(fn (Patient $patient) => [
                    'id' => $patient->id,
                    'uuid' => $patient->uuid,
                    'patient_number' => $patient->patient_number,
                    'first_name' => $patient->first_name,
                    'last_name' => $patient->last_name,
                    'birth_date' => $patient->birth_date?->toDateString(),
                    'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                    'age' => $patient->birth_date?->age,
                    'sex' => $patient->sex->value,
                    'civility' => $patient->civility?->value,
                    'identity_document_type' => $patient->identity_document_type?->value,
                    'identity_document_number' => $patient->identity_document_number,
                    'phone' => $patient->phone,
                    'email' => $patient->email,
                    'address' => $patient->address,
                    'emergency_contact_name' => $patient->emergency_contact_name,
                    'emergency_contact_phone' => $patient->emergency_contact_phone,
                    'emergency_contact_relationship' => $patient->emergency_contact_relationship,
                    'emergency_contact_email' => $patient->emergency_contact_email,
                ])
            : collect();

        // "identifier les patients présents" / "consulter le statut du
        // parcours patient" (CDC §5.2.1) — recent activity, not filtered to
        // today only: the receptionist also needs to see who's still mid-
        // passage from a day or two ago.
        $recentEpisodes = Episode::query()
            ->with('patient:id,uuid,patient_number,first_name,last_name')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['id', 'patient_id', 'episode_number', 'status', 'priority', 'administrative_status', 'started_at']);

        return Inertia::render('Reception/Create', [
            'search' => $search,
            'matches' => $matches,
            'recentEpisodes' => $recentEpisodes,
        ]);
    }

    public function store(StoreArrivalRequest $request, RegisterArrivalAction $action): RedirectResponse
    {
        try {
            $episode = $action->execute(
                existingPatientId: $request->input('patient_id'),
                newPatientData: $request->filled('patient_id')
                    ? null
                    : $request->safe()->except(['is_emergency']),
                existingPatientData: $request->filled('patient_id') && $request->boolean('update_patient')
                    ? $request->safe()->except(['patient_id', 'is_emergency', 'update_patient'])
                    : null,
                confirmDuplicate: $request->boolean('confirm_duplicate'),
                priority: $request->boolean('is_emergency')
                    ? EpisodePriority::Emergency
                    : EpisodePriority::Normal,
            );
        } catch (DuplicatePatientException $e) {
            return back()->withInput()->with('duplicates', $e->matches->map(fn (Patient $p) => [
                'id' => $p->id,
                'patient_number' => $p->patient_number,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'birth_date' => $p->birth_date->toDateString(),
            ])->all());
        }

        $message = $episode->priority === EpisodePriority::Emergency
            ? "Passage urgence {$episode->episode_number} créé et orienté vers Médecine / Soins."
            : "Passage {$episode->episode_number} créé.";

        return redirect()->route('patients.show', $episode->patient)
            ->with('status', $message);
    }
}
