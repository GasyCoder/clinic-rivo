<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Episode\SetEpisodeReceptionNextStepsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reception\UpdateEpisodeNextStepsRequest;
use App\Models\Episode;
use Illuminate\Http\RedirectResponse;

/**
 * ADR-177 — la Réception complète ou corrige sa suggestion après l'accueil :
 * « si l'équipe veut plus tard une recommandation, la Réception peut cocher
 * Soins ». Rien d'autre ne change — ni orientation, ni visibilité.
 */
class EpisodeNextStepController extends Controller
{
    public function update(
        UpdateEpisodeNextStepsRequest $request,
        Episode $episode,
        SetEpisodeReceptionNextStepsAction $action,
    ): RedirectResponse {
        $steps = $action->execute($episode, $request->validated('next_steps') ?? [], $request->user());

        return back()->with('status', $steps === []
            ? 'Aucune prochaine étape suggérée pour ce passage.'
            : 'Prochaine étape suggérée enregistrée.');
    }
}
