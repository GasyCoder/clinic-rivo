<?php

namespace App\Models;

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
 */
#[Fillable(['enabled', 'min_episodes', 'min_amount', 'window_months', 'updated_by', 'external_updated_by_uuid', 'external_updated_by_name'])]
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
