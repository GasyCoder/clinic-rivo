<?php

namespace App\Http\Controllers;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\OrientEpisodeAction;
use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;

class EpisodeController extends Controller
{
    public function store(Patient $patient, CreateEpisodeAction $action): RedirectResponse
    {
        $episode = $action->execute($patient);

        return redirect()->route('patients.show', $patient)
            ->with('status', "Passage {$episode->episode_number} créé.");
    }

    public function orient(Episode $episode, OrientEpisodeAction $action): RedirectResponse
    {
        $action->execute($episode);

        return back()->with('status', 'Patient orienté.');
    }
}
