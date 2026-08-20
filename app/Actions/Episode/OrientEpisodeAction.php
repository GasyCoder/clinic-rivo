<?php

namespace App\Actions\Episode;

use App\Models\Episode;

class OrientEpisodeAction
{
    public function execute(Episode $episode): Episode
    {
        $episode->orient();

        return $episode;
    }
}
