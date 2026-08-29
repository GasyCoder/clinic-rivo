<?php

namespace App\Http\Controllers;

use App\Actions\Episode\MarkEpisodeEmergencyAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EpisodeEmergencyController extends Controller
{
    public function fromReception(
        Request $request,
        Episode $episode,
        MarkEpisodeEmergencyAction $action,
    ): RedirectResponse {
        $alreadyEmergency = $episode->priority === EpisodePriority::Emergency;
        $episode = $action->execute($episode, CatalogModule::Reception, $request->user());

        return redirect()->route('patients.show', $episode->patient)
            ->with(
                'status',
                $alreadyEmergency
                    ? "Le passage {$episode->episode_number} était déjà classé en urgence."
                    : "Passage {$episode->episode_number} classé en urgence. Soins et Médecine ont été alertés.",
            );
    }

    public function fromMedicine(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        MarkEpisodeEmergencyAction $action,
    ): RedirectResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);

        $episodeOrientation->load('episode');
        $alreadyEmergency = $episodeOrientation->episode->priority === EpisodePriority::Emergency;
        $episode = $action->execute(
            $episodeOrientation->episode,
            CatalogModule::Medicine,
            $request->user(),
            $episodeOrientation,
        );

        return back()->with(
            'status',
            $alreadyEmergency
                ? "Le passage {$episode->episode_number} était déjà classé en urgence."
                : "Passage {$episode->episode_number} classé en urgence. La file Soins a été alertée sans interrompre la consultation.",
        );
    }
}
