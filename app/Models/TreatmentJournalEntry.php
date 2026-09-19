<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-116 — une ligne saisie à la main du « Dossier médical – Traitement ».
 *
 * Le reste du journal est lu depuis les actes déjà enregistrés (soins,
 * ordonnances, demandes) : cette table ne porte que ce que l'application ne
 * sait pas encore, par exemple un traitement administré au lit.
 *
 * Append-only, comme un acte réalisé (ADR-032) : une erreur se corrige par
 * une nouvelle ligne, jamais en réécrivant l'ancienne. Le visa est l'auteur.
 */
#[Fillable(['episode_id', 'occurred_at', 'description', 'recorded_by'])]
class TreatmentJournalEntry extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'medicine';
    }
}
