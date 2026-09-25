<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Les seuils qui font d'un patient un patient VIP sur ce site (ADR-133).
 *
 * Une seule ligne. Le statut lui-même n'est jamais stocké : il se calcule à
 * chaque lecture depuis les passages et les encaissements de la fenêtre, si
 * bien qu'un patient entre dans la catégorie — ou en sort — sans que personne
 * n'ait à la mettre à jour.
 *
 * ADR-192 — la remise VIP se règle ici, avec les seuils : facultative, un
 * pourcentage ou un montant sur la part à la charge du patient.
 */
#[Fillable(['enabled', 'min_episodes', 'min_amount', 'window_months', 'discount_type', 'discount_value', 'updated_by', 'external_updated_by_uuid', 'external_updated_by_name'])]
class PatientVipSetting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'min_episodes' => 'integer',
            'min_amount' => 'decimal:2',
            'window_months' => 'integer',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
