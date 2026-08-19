<?php

namespace App\Enums;

/**
 * No status enum is given anywhere in the CDC for Chirurgie — this is
 * inferred from the ordering implied by CDC GitHub §15/16's own permission
 * names (schedule → preoperative.validate → intervention → report.validate
 * → discharge), the minimum needed for surgery.preoperative.validate and
 * surgery.report.validate to have a state to act on. Flagged as an
 * assumption, not a transcribed CDC rule.
 */
enum SurgicalRequestStatus: string
{
    case Pending = 'PENDING';
    case Scheduled = 'SCHEDULED';
    case PreoperativeValidated = 'PREOPERATIVE_VALIDATED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Discharged = 'DISCHARGED';
}
