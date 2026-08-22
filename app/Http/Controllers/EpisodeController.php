<?php

namespace App\Http\Controllers;

use App\Actions\Episode\CreateEpisodeAction;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EpisodeController extends Controller
{
    public function store(Request $request, Patient $patient, CreateEpisodeAction $action): RedirectResponse
    {
        $episode = $action->execute($patient, actor: $request->user());

        return redirect()->route('reception.passages.services.show', $episode)
            ->with('status', "Passage {$episode->episode_number} créé. Sélectionnez maintenant les prestations demandées.");
    }
}
