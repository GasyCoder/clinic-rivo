<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-162 — la note quotidienne du séjour, courte et structurée S/O/A/P.
 *
 * Elle remplace la visite de service (ADR-148), qui obligeait le médecin à
 * ouvrir une consultation entière pour écrire trois lignes. Comme une ligne du
 * journal de traitement (ADR-116) ou un diagnostic (ADR-035), elle est
 * append-only : une erreur se corrige par une nouvelle note, jamais en
 * réécrivant celle qu'un confrère a déjà lue.
 */
#[Fillable(['hospital_stay_id', 'subjective', 'objective', 'assessment', 'plan', 'written_at', 'written_by'])]
class HospitalStayNote extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['written_at' => 'datetime'];
    }

    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }

    public function writtenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Une note de séjour ne se réécrit pas : ajoutez une nouvelle note (ADR-162).');
        });

        static::deleting(function (): void {
            throw new \LogicException('Une note de séjour ne se supprime pas (ADR-162).');
        });
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
