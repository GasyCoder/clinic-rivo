<?php

namespace App\Exceptions;

use App\Models\Episode;
use RuntimeException;

/**
 * Raised by any status transition on Episode (global status or
 * administrative_status) attempted from a state that doesn't allow it —
 * e.g. cancelling an already-CLOSED episode, or re-orienting one that was
 * never PENDING_ORIENTATION. $attempted/$from are the raw enum values
 * (string) rather than a typed enum so this one exception serves both
 * status columns without coupling to either enum specifically.
 */
class InvalidEpisodeTransitionException extends RuntimeException
{
    public function __construct(public readonly Episode $episode, string $attempted, string $from)
    {
        parent::__construct(sprintf(
            "Impossible de passer l'épisode %s au statut %s : il est déjà %s.",
            $episode->episode_number,
            $attempted,
            $from,
        ));
    }
}
