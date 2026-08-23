<?php

namespace App\Exceptions;

use App\Models\EpisodeOrientation;
use RuntimeException;

class InvalidEpisodeOrientationTransitionException extends RuntimeException
{
    public function __construct(
        public readonly EpisodeOrientation $orientation,
        string $attempted,
        string $from,
    ) {
        parent::__construct(sprintf(
            "Impossible de passer l'orientation du passage %s vers %s : elle est déjà %s.",
            $orientation->episode?->episode_number ?? (string) $orientation->episode_id,
            $attempted,
            $from,
        ));
    }
}
