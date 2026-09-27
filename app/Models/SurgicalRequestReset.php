<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ADR-171 — l'archive d'une réinitialisation : ce que le dossier du bloc
 * contenait avant d'être remis à zéro. Écrite une fois, jamais corrigée ni
 * supprimée : c'est la trace qui rend la réinitialisation acceptable.
 */
#[Fillable(['surgical_request_id', 'previous_status', 'snapshot', 'reason', 'reset_by', 'reset_at'])]
class SurgicalRequestReset extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'reset_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une réinitialisation archivée ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une réinitialisation archivée ne se supprime pas.'));
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reset_by');
    }
}
