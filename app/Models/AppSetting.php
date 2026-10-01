<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Hr\BadgeDesign;
use App\Support\Laboratory\LabReportDesign;
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
    'ui_font_family', 'ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast',
    'patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator',
    'patient_number_reset', 'episode_number_digits',
    'employee_number_prefix', 'employee_number_separator', 'employee_number_digits',
    'auth_template', 'auth_background_path', 'profile_template',
    // ADR-209 — le badge du personnel : chaque réglage de BadgeDesign::FIELDS, et l'emblème déposé.
    'badge_primary_color', 'badge_accent_color', 'badge_text_color', 'badge_background_color',
    'badge_tagline', 'badge_seal_top', 'badge_seal_bottom', 'badge_intern_label', 'badge_number_label', 'badge_footer_text',
    'badge_logo_style', 'badge_icon', 'badge_font', 'badge_tagline_font', 'badge_name_case', 'badge_name_order', 'badge_text_case',
    'badge_orientation', 'badge_card_size', 'badge_photo_shape', 'badge_corners', 'badge_paper', 'badge_paper_orientation',
    'badge_name_size', 'badge_text_size', 'badge_tagline_size', 'badge_page_margin', 'badge_gap',
    'badge_card_width', 'badge_card_height',
    'badge_show_photo', 'badge_show_tagline', 'badge_show_icon', 'badge_show_department', 'badge_show_job',
    'badge_show_number', 'badge_show_validity', 'badge_show_site', 'badge_show_watermark', 'badge_show_decorations',
    'badge_show_qr', 'badge_cut_marks', 'badge_logo_path',
    'currency_label', 'currency_position', 'currency_decimals',
    'baby_max_age', 'child_max_age',
    'director_name', 'director_title', 'signature_path',
    'legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account',
    'staff_discount_type', 'staff_discount_value',
    // ADR-223 — le compte rendu d'analyses : chaque réglage de LabReportDesign::FIELDS, et son logo.
    ...LabReportDesign::FIELDS, 'lab_report_logo_path',
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
            // ADR-209 — les interrupteurs et les nombres du badge.
            ...array_fill_keys(array_keys(BadgeDesign::SWITCHES), 'boolean'),
            ...array_fill_keys(array_keys(BadgeDesign::NUMBERS), 'integer'),
            // ADR-223 — les interrupteurs et les nombres du compte rendu d'analyses.
            ...array_fill_keys(array_keys(LabReportDesign::SWITCHES), 'boolean'),
            ...array_fill_keys(array_keys(LabReportDesign::NUMBERS), 'integer'),
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
