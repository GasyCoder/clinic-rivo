<?php

namespace App\Models;

use App\Enums\SurgicalTeamFunction;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC §9's "équipe bloc" (chirurgien, anesthésiste, infirmier de bloc,
 * paramédical). No dedicated CDC permission — assignment is gated behind
 * the generic surgery.update at the route/Policy layer.
 */
#[Fillable(['surgical_request_id', 'user_id', 'function', 'assigned_by', 'assigned_at'])]
class SurgicalTeamMember extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'function' => SurgicalTeamFunction::class,
            'assigned_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
