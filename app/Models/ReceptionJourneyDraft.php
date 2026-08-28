<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Temporary server-side state used to resume the progressive Reception UI. */
#[Fillable(['episode_id', 'catalog_lines', 'designation_deferred', 'created_by'])]
class ReceptionJourneyDraft extends Model
{
    protected function casts(): array
    {
        return [
            'catalog_lines' => 'array',
            'designation_deferred' => 'boolean',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
