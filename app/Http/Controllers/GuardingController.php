<?php

namespace App\Http\Controllers;

use App\Actions\Guarding\RecordExitControlAction;
use App\Http\Requests\RecordExitControlRequest;
use App\Models\Episode;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-116 — le poste de gardiennage à la porte.
 *
 * C'est la « Signature Service Sécurité » du ticket de sortie (ADR-023/026),
 * jamais réellement câblée jusqu'ici : le catalogue `guarding.*` existait
 * sans le moindre écran (ADR-101 l'aurait signalé « pas encore vérifiée »).
 * Le gardien ne décide jamais d'une sortie ; il vérifie que la Caisse l'a
 * déjà prononcée (ADR-090), puis constate le départ physique.
 */
class GuardingController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $matching = fn (Builder $query) => $query
            ->where(fn (Builder $inner) => $inner
                ->where('episode_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $patient) => $patient
                    ->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")));

        $toControl = Episode::query()
            ->with('patient:id,uuid,patient_number,first_name,last_name,deleted_at')
            ->whereIn('administrative_status', ['DISCHARGED_PAID', 'DISCHARGED_DEBT'])
            ->whereDoesntHave('exitControl')
            ->when($search !== '', $matching)
            ->orderByDesc('administrative_exit_at')
            ->limit(50)
            ->get();

        $controlled = Episode::query()
            ->with(['patient:id,uuid,patient_number,first_name,last_name,deleted_at', 'exitControl.controlledBy:id,name'])
            ->whereHas('exitControl')
            ->when($search !== '', $matching)
            ->get()
            // Sorted in PHP: the relation lives on a joined table, and this
            // queue is small enough (one row per still-recent exit) that a
            // second query or a manual join would only add complexity.
            ->sortByDesc(fn (Episode $episode) => $episode->exitControl->controlled_at)
            ->take(30)
            ->values();

        $present = fn (Episode $episode) => [
            'uuid' => $episode->uuid,
            'episode_number' => $episode->episode_number,
            'administrative_status' => $episode->administrative_status?->value,
            'administrative_exit_type' => $episode->administrative_exit_type?->value,
            'administrative_exit_type_label' => $episode->administrative_exit_type?->label(),
            'administrative_exit_at' => $episode->administrative_exit_at,
            'exit_control' => $episode->exitControl ? [
                'controlled_at' => $episode->exitControl->controlled_at,
                'controlled_by' => $episode->exitControl->controlledBy?->name,
                'notes' => $episode->exitControl->notes,
            ] : null,
            'patient' => $episode->patient ? [
                'uuid' => $episode->patient->uuid,
                'patient_number' => $episode->patient->patient_number,
                'first_name' => $episode->patient->first_name,
                'last_name' => $episode->patient->last_name,
                'deleted_at' => $episode->patient->deleted_at,
            ] : null,
        ];

        $user = $request->user();

        return Inertia::render('Guarding/Index', [
            'filters' => ['q' => $search],
            'toControl' => $toControl->map($present)->values(),
            'controlled' => $controlled->map($present)->values(),
            'capabilities' => [
                'can_close' => $user->can('guarding.entries.close'),
                'can_view_visitors' => $user->can('visitors.view'),
            ],
        ]);
    }

    public function store(
        RecordExitControlRequest $request,
        Episode $episode,
        RecordExitControlAction $action,
    ): RedirectResponse {
        $action->execute($episode, $request->user(), $request->validated('notes'));

        return back()->with('status', "Sortie de {$episode->episode_number} constatée.");
    }
}
