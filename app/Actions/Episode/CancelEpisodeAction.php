<?php

namespace App\Actions\Episode;

use App\Models\Episode;

class CancelEpisodeAction
{
    public function execute(Episode $episode, string $reason): Episode
    {
        $episode->cancel($reason);

        return $episode;
    }
}
