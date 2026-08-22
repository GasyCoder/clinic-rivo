<?php

namespace App\Actions\Episode;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CreateEpisodeOrientationAction
{
    public function execute(
        Episode $episode,
        CatalogModule $source,
        CatalogModule $destination,
        ?User $actor = null,
        ?string $reason = null,
    ): EpisodeOrientation {
        $activeKey = $episode->getKey().':'.$destination->value;

        return EpisodeOrientation::query()->firstOrCreate(
            ['active_key' => $activeKey],
            [
                'episode_id' => $episode->getKey(),
                'source_module' => $source,
                'destination_module' => $destination,
                'status' => EpisodeOrientationStatus::Pending,
                'reason' => $reason,
                'oriented_by' => $actor?->getKey() ?? Auth::id(),
                'oriented_at' => now(),
            ],
        );
    }
}
