<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unvalidated typing in progress on the Médecine consultation, kept
 * server-side so a reload does not lose it.
 *
 * Deliberately NOT Auditable: it is rewritten every few seconds while the
 * doctor types, and flooding the audit trail with keystroke batches would
 * bury the entries that matter. What gets audited is the real record.
 */
#[Fillable(['episode_orientation_id', 'created_by', 'payload'])]
class ConsultationDraft extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function orientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'episode_orientation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
