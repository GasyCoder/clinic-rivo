<?php

namespace App\Models;

use App\Enums\AdministrationRoute;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'ordonnance type d'un protocole (ADR-111).
 *
 * Paramétrage, pas enregistrement clinique : la liste est remplacée à chaque
 * enregistrement du protocole, comme le matériel habituel d'un acte (ADR-072).
 * L'audit est porté par le protocole, qui consigne l'ancienne et la nouvelle
 * liste.
 */
#[Fillable([
    'clinical_protocol_id', 'medicine_id', 'dosage', 'route', 'frequency',
    'duration', 'quantity', 'instructions', 'sort_order',
])]
class ClinicalProtocolLine extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'route' => AdministrationRoute::class,
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocol::class, 'clinical_protocol_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
