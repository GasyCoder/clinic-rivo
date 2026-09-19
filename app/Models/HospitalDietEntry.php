<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-113 — une ligne de la fiche de régime : un jour, une heure, les quatre
 * colonnes « Régime » de la feuille papier et une observation, en texte
 * libre. Aucun montant : la fiche est un suivi, jamais une facturation.
 *
 * Corrigeable, jamais supprimée : `Auditable` garde l'ancienne et la
 * nouvelle valeur de chaque correction (même principe que la fiche Soins,
 * ADR-092).
 */
#[Fillable([
    'hospital_stay_id', 'served_on', 'served_time',
    'tea_bread', 'sosoa_brochette', 'yogurt', 'puree', 'observation',
    'recorded_by', 'updated_by',
])]
class HospitalDietEntry extends Model
{
    use Auditable, HasUuid;

    /** Les quatre colonnes « Régime », dans l'ordre de la feuille. */
    public const MEAL_FIELDS = ['tea_bread', 'sosoa_brochette', 'yogurt', 'puree'];

    protected function casts(): array
    {
        return [
            'served_on' => 'date',
        ];
    }

    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'hospitalization';
    }
}
