<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Les paramètres de l'application sur ce déploiement (ADR-184).
 *
 * Une seule ligne. Une colonne vide ne veut pas dire « rien » : elle laisse la
 * configuration de déploiement s'appliquer (`App\Services\Settings\AppSettings`),
 * si bien qu'un site que personne n'a encore réglé s'affiche comme avant.
 */
#[Fillable([
    'app_name', 'app_tagline', 'primary_color', 'logo_path', 'icon_path', 'search_engines_hidden',
    'theme_preset', 'light_background', 'light_foreground', 'dark_primary_color', 'dark_background', 'dark_foreground',
    'ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast',
    'patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator',
    'patient_number_reset', 'episode_number_digits',
    'employee_number_prefix', 'employee_number_separator', 'employee_number_digits',
    'auth_template', 'auth_background_path', 'profile_template',
    'currency_label', 'currency_position', 'currency_decimals',
    'baby_max_age', 'child_max_age',
    'director_name', 'director_title', 'signature_path',
    'legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account',
    'staff_discount_type', 'staff_discount_value',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
])]
class AppSetting extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'currency_decimals' => 'integer',
            'search_engines_hidden' => 'boolean',
            'baby_max_age' => 'integer',
            'child_max_age' => 'integer',
            'ui_font_size' => 'integer',
            'patient_number_digits' => 'integer',
            'episode_number_digits' => 'integer',
            'employee_number_digits' => 'integer',
            'staff_discount_value' => 'decimal:2',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'settings';
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
