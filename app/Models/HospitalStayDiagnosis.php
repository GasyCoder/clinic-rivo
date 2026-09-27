<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ADR-147 — un diagnostic posé au terme d'un séjour hospitalier.
 *
 * Il vit sur le séjour, jamais dans la consultation qui a demandé
 * l'hospitalisation : celle-ci est le plus souvent close (ADR-076), et ce que
 * le médecin conclut après plusieurs jours de séjour n'en est pas une
 * correction.
 *
 * **Append-only**, comme tout diagnostic (ADR-035) : une erreur se corrige par
 * une nouvelle ligne, jamais en réécrivant celle-ci.
 */
#[Fillable([
    'hospital_stay_id', 'diagnostic_catalog_id', 'description',
    'catalog_code_snapshot', 'catalog_name_snapshot', 'notes', 'recorded_by',
])]
class HospitalStayDiagnosis extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Un diagnostic de sortie est append-only : posez-en un nouveau (ADR-035).');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Un diagnostic de sortie ne se supprime pas (ADR-010).');
        });
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class, 'hospital_stay_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(DiagnosticCatalog::class, 'diagnostic_catalog_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
