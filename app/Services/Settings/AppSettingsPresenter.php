<?php

namespace App\Services\Settings;

use App\Actions\Settings\UpdateAppSettingsAction;
use App\Models\DiscountCoupon;
use App\Models\PatientStaffLink;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Patient\PatientNumberGenerator;
use App\Support\Numbering\EmployeeNumberFormat;
use App\Support\Settings\ThemePresets;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Les paramètres tels que l'écran du portail les lit (ADR-184) : les valeurs
 * réglées, ce que la configuration donne à défaut — pour que le champ vide dise
 * ce qui s'affichera quand même — et un aperçu des fichiers déposés.
 *
 * Le même texte sert l'API d'un site et le portail pour lui-même.
 */
class AppSettingsPresenter
{
    public function __construct(private readonly AppSettings $settings) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $setting = $this->settings->setting();
        $currency = $this->settings->currency();
        $ages = $this->settings->ageBands();

        $values = collect(UpdateAppSettingsAction::FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $setting?->{$field}])
            ->all();

        return [
            'site' => [
                'code' => config('rivo.site.code'),
                'name' => config('rivo.site.name'),
                'type' => config('rivo.site.type'),
            ],
            'configured' => $setting !== null,
            'values' => [
                ...$values,
                'currency_label' => $currency['label'],
                'currency_position' => $currency['position'],
                'currency_decimals' => $currency['decimals'],
                'baby_max_age' => $ages['baby_max_age'],
                'child_max_age' => $ages['child_max_age'],
                // L'état réellement appliqué : la case dit ce que les moteurs voient.
                'search_engines_hidden' => $this->settings->hiddenFromSearchEngines(),
                // Le modèle réellement appliqué, pas seulement celui qui a été réglé.
                'auth_template' => $this->settings->authTemplate()->value,
                'profile_template' => $this->settings->profileTemplate()->value,
                // ADR-191 — le thème réellement appliqué : réglé, « Personnalisé » ou RIVO.
                'theme_preset' => $this->settings->themePreset(),
            ],
            // Ce qui s'applique quand un champ reste vide : la configuration du déploiement.
            'fallbacks' => [
                'app_name' => config('rivo.brand'),
                'app_tagline' => config('rivo.tagline'),
                'search_engines_hidden' => (bool) config('rivo.search_engines.hidden', true),
                // Une adresse de l'application elle-même (`/images/…`) : le portail, qui
                // partage le code, sait l'afficher en aperçu.
                'auth_background_url' => config('rivo.auth_cover_url'),
                'director_title' => AppSettings::DEFAULT_DIRECTOR_TITLE,
                'legal_nif' => config('rivo.documents.nif'),
                'legal_stat' => config('rivo.documents.stat'),
                'legal_address' => config('rivo.documents.address'),
                'legal_phone' => config('rivo.documents.phone'),
                'legal_email' => config('rivo.documents.email'),
                // ADR-191 — les couleurs d'origine de chaque mode, et les formats par défaut.
                'theme' => ThemePresets::ORIGIN,
                'patient_number_prefix' => strtoupper((string) config('rivo.site.code')) ?: 'X',
                'employee_number_prefix' => EmployeeNumberFormat::DEFAULT_PREFIX,
            ],
            'appearance' => $this->settings->appearance()['site'],
            'numbering' => $this->numbering(),
            'discounts' => $this->discounts(),
            'assets' => collect(AppSettings::ASSET_KINDS)->mapWithKeys(fn (string $kind) => [$kind => [
                'present' => $this->settings->assetExists($kind),
                'data_url' => $this->settings->assetPreviewDataUri($kind),
            ]])->all(),
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->external_updated_by_name ?? $setting?->updatedBy?->name,
        ];
    }

    /**
     * ADR-192 — les coupons de ce site, les plus récents d'abord, et le nombre de
     * membres du personnel reliés à un dossier patient. La remise VIP se règle
     * avec ses seuils, dans Patients VIP : rien à en servir ici.
     *
     * @return array<string, mixed>
     */
    private function discounts(): array
    {
        try {
            $coupons = DiscountCoupon::query()->latest('id')->limit(200)->get();
            $staffLinked = PatientStaffLink::query()->active()->count();
        } catch (Throwable) {
            return ['available' => false, 'coupons' => []];
        }

        return [
            'available' => true,
            'staff_linked' => $staffLinked,
            'coupons' => $coupons->map(fn (DiscountCoupon $coupon) => [
                'uuid' => $coupon->uuid,
                'code' => $coupon->code,
                'label' => $coupon->label,
                'discount_type' => $coupon->discount_type->value,
                'discount_value' => (string) $coupon->discount_value,
                'describe' => $coupon->discount_type->describe((string) $coupon->discount_value),
                'valid_from' => $coupon->valid_from?->toDateString(),
                'valid_until' => $coupon->valid_until?->toDateString(),
                'max_uses' => $coupon->max_uses,
                'uses_count' => $coupon->uses_count,
                'archived' => $coupon->archived_at !== null,
                'unusable_reason' => $coupon->unusableReason(),
                'created_at' => $coupon->created_at?->toIso8601String(),
                'created_by' => $coupon->external_created_by_name,
            ])->all(),
        ];
    }

    /**
     * ADR-191 — ce que l'aperçu de la numérotation doit connaître du site : ses
     * compteurs (de l'année et continu) et les prochains numéros réels avec la
     * forme enregistrée. Rien n'est réservé ni consommé.
     *
     * @return array<string, mixed>
     */
    private function numbering(): array
    {
        $year = now()->year;

        try {
            $counters = DB::table('patient_number_sequences')->whereIn('year', [$year, 0])->pluck('next_number', 'year');
            $patientNext = app(PatientNumberGenerator::class)->peek();
            $employeeNext = app(EmployeeNumberAllocator::class)->suggest();
        } catch (Throwable) {
            return ['available' => false, 'current_year' => $year];
        }

        return [
            'available' => true,
            'current_year' => $year,
            'yearly_next' => (int) ($counters[$year] ?? 1),
            'continuous_next' => (int) ($counters[0] ?? 1),
            'patient_next' => $patientNext,
            'employee_next' => $employeeNext,
        ];
    }
}
