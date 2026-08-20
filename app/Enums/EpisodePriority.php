<?php

namespace App\Enums;

/**
 * Priority belongs to one passage, never permanently to the patient.
 * EMERGENCY bypasses the ordinary reception-orientation wait while the
 * family completes the same administrative form used for every patient.
 */
enum EpisodePriority: string
{
    case Normal = 'NORMAL';
    case Emergency = 'EMERGENCY';
}
