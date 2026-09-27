<?php

namespace App\Models;

use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-170 — une réserve posée par une autorisation sous conditions.
 *
 * Tant qu'une seule reste ouverte, l'intervention ne démarre pas : c'est ce qui
 * distingue « autorisé sous conditions » d'un « autorisé » assorti d'une note
 * que personne n'est obligé de lire.
 */
#[Fillable([
    'anesthesia_record_id', 'label', 'status',
    'created_by', 'resolved_by', 'resolved_at', 'resolution_notes',
])]
class AnesthesiaClearanceCondition extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'status' => AnesthesiaClearanceConditionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function anesthesiaRecord(): BelongsTo
    {
        return $this->belongsTo(AnesthesiaRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === AnesthesiaClearanceConditionStatus::Open;
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
