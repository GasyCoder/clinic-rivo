<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Une version d'un compte rendu d'imagerie, telle qu'elle était avant d'être
 * corrigée (ADR-130).
 *
 * Append-only : une version remplacée ne se réécrit ni ne se supprime — c'est
 * ce qui rend une correction acceptable (ADR-010).
 */
#[Fillable([
    'imaging_request_item_id', 'revision', 'result_value', 'result_notes',
    'resulted_at', 'resulted_by', 'superseded_at', 'superseded_by', 'reason',
])]
class ImagingResultRevision extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return ['resulted_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Une version de compte rendu ne se modifie jamais.');
        });

        static::deleting(function (): void {
            throw new LogicException('Une version de compte rendu ne se supprime jamais.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ImagingRequestItem::class, 'imaging_request_item_id');
    }

    public function resultedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resulted_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }
}
