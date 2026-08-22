<?php

namespace App\Http\Controllers;

use App\Actions\Episode\CreateEpisodeAction;
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
}
