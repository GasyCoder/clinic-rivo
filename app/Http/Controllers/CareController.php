<?php

namespace App\Http\Controllers;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Support\EpisodeQueuePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CareController extends Controller
{
    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), ['active', 'oriented'], true)
            ? (string) $request->query('filter')
            : 'active';
        $search = trim((string) $request->query('q', ''));

        $baseQuery = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Care->value)
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'));

        $counts = [
            'active' => (clone $baseQuery)->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])->count(),
            'oriented' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::Completed->value)
                ->count(),
        ];

        $orientations = $baseQuery
            ->with([
                'episode.patient',
                'episode.billableItems',
                'acceptedBy:id,name',
            ])
            ->when(
                $filter === 'oriented',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::Completed->value),
                fn ($query) => $query->whereIn('status', [
                    EpisodeOrientationStatus::Pending->value,
                    EpisodeOrientationStatus::InProgress->value,
                ]),
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
            ->orderByDesc($filter === 'oriented' ? 'completed_at' : 'oriented_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EpisodeOrientation $orientation) => $presenter->present($orientation));

        return Inertia::render('Care/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function accept(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        AcceptCareOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return back()->with('status', 'Patient pris en charge aux Soins.');
    }

    public function complete(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        CompleteCareAndOrientToMedicineAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return back()->with('status', 'Soins terminés. Le patient est maintenant en attente en Médecine.');
    }
}
