<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-116 — la sortie constatée par le gardien à la porte.
 *
 * C'est la ligne « Signature Service Sécurité » du ticket de sortie : le
 * gardien vérifie que la Caisse a prononcé la sortie administrative, puis
 * constate le départ. Un seul contrôle par passage, jamais modifié.
 */
#[Fillable(['episode_id', 'controlled_at', 'controlled_by', 'notes'])]
class EpisodeExitControl extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'controlled_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function controlledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controlled_by');
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'guarding';
    }
}
