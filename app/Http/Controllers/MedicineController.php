<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Support\EpisodeQueuePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicineController extends Controller
{
    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), ['all', 'waiting', 'in_progress'], true)
            ? (string) $request->query('filter')
            : 'all';
        $search = trim((string) $request->query('q', ''));

        $baseQuery = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'));

        $counts = [
            'all' => (clone $baseQuery)->count(),
            'waiting' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::Pending->value)
                ->count(),
            'in_progress' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::InProgress->value)
                ->count(),
        ];

        $orientations = $baseQuery
            ->with([
                'episode.patient',
                'episode.billableItems',
                'acceptedBy:id,name',
            ])
            ->when(
                $filter === 'waiting',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::Pending->value),
            )
            ->when(
                $filter === 'in_progress',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::InProgress->value),
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('episode', function ($episodeQuery) use ($search): void {
                    $episodeQuery->where('episode_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($patientQuery) use ($search): void {
                            $patientQuery->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM episodes WHERE episodes.id = episode_orientations.episode_id AND episodes.priority = 'EMERGENCY') THEN 0 ELSE 1 END")
            ->orderBy('oriented_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EpisodeOrientation $orientation) => $presenter->present($orientation));

        return Inertia::render('Medicine/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function accept(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        AcceptMedicineOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return back()->with('status', 'Patient pris en charge en Médecine.');
    }
}
