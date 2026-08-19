<?php

namespace App\Http\Controllers;

use App\Actions\Reception\RegisterArrivalAction;
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
                ->get(['id', 'patient_number', 'first_name', 'last_name', 'birth_date', 'phone'])
            : collect();

        // "identifier les patients présents" / "consulter le statut du
        // parcours patient" (CDC §5.2.1) — recent activity, not filtered to
        // today only: the receptionist also needs to see who's still mid-
        // passage from a day or two ago.
        $recentEpisodes = Episode::query()
            ->with('patient:id,patient_number,first_name,last_name')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['id', 'patient_id', 'episode_number', 'status', 'administrative_status', 'started_at']);

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
                newPatientData: $request->filled('patient_id') ? null : $request->validated(),
                confirmDuplicate: $request->boolean('confirm_duplicate'),
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

        return redirect()->route('patients.show', $episode->patient_id)
            ->with('status', "Passage {$episode->episode_number} créé.");
    }
}
