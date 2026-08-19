<?php

namespace App\Enums;

/**
 * Distinguishes the two structurally identical CDC permissions
 * surgery.care.create (peropératoire) and surgery.postoperative_care.create
 * (postopératoire) — see surgical_care_notes migration.
 */
enum SurgicalCarePhase: string
{
    case Perioperative = 'PERIOPERATIVE';
    case Postoperative = 'POSTOPERATIVE';
}
