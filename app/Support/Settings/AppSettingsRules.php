<?php

namespace App\Support\Settings;

use App\Enums\AuthTemplate;
use App\Enums\ProfileTemplate;
use App\Rules\ReadableThemeColors;
use App\Services\Settings\AppSettings;
use App\Support\Billing\DiscountRules;
use App\Support\Numbering\EmployeeNumberFormat;
use App\Support\Numbering\PatientNumberFormat;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

/**
 * Les règles des paramètres de l'application (ADR-184), écrites une fois pour
 * l'API du site et pour le portail : deux copies finiraient par accepter d'un
 * côté ce que l'autre refuse.
 */
final class AppSettingsRules
{
    /** Bornes des tranches d'âge, en années révolues. */
    public const BABY_MAX_AGE_LIMIT = 5;

    public const CHILD_MAX_AGE_LIMIT = 20;

    /** Taille maximale de chaque fichier, en kilo-octets. */
    public const ASSET_MAX_KB = ['logo' => 1024, 'icon' => 512, 'signature' => 512, 'background' => 2048];

    /** SVG exclu : un fichier servi tel quel ne doit jamais pouvoir exécuter un script. */
    public const ASSET_MIMES = ['logo' => 'png,jpg,jpeg,webp', 'icon' => 'png,ico,webp', 'signature' => 'png,jpg,jpeg,webp', 'background' => 'jpg,jpeg,png,webp'];

    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** @return array<string, array<int, mixed>> */
    public static function settings(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:80'],
            'app_tagline' => ['nullable', 'string', 'max:150'],
            // Absent, la configuration du déploiement décide (masquée par défaut).
            'search_engines_hidden' => ['sometimes', 'boolean'],
            'auth_template' => ['nullable', Rule::enum(AuthTemplate::class)],
            'profile_template' => ['nullable', Rule::enum(ProfileTemplate::class)],
            'primary_color' => ['nullable', 'string', 'regex:'.self::HEX],
            // ADR-191 — thème : préréglage et couleurs de chaque mode (vide = couleur d'origine).
            'theme_preset' => ['nullable', Rule::in(ThemePresets::keys())],
            'light_background' => ['nullable', 'string', 'regex:'.self::HEX, new ReadableThemeColors('light')],
            'light_foreground' => ['nullable', 'string', 'regex:'.self::HEX, new ReadableThemeColors('light')],
            'dark_primary_color' => ['nullable', 'string', 'regex:'.self::HEX],
            'dark_background' => ['nullable', 'string', 'regex:'.self::HEX, new ReadableThemeColors('dark')],
            'dark_foreground' => ['nullable', 'string', 'regex:'.self::HEX, new ReadableThemeColors('dark')],
            // Réglages avancés : les valeurs par défaut du site.
            'ui_font_size' => ['nullable', 'integer', Rule::in(UiOptions::FONT_SIZES)],
            'ui_density' => ['nullable', Rule::in(UiOptions::DENSITIES)],
            'ui_radius' => ['nullable', Rule::in(UiOptions::RADII)],
            'ui_motion' => ['nullable', Rule::in(UiOptions::MOTIONS)],
            'ui_contrast' => ['nullable', Rule::in(UiOptions::CONTRASTS)],
            // Numérotation : vide = le format de l'ADR-030 et le matricule EMP-0001.
            'patient_number_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'patient_number_year' => ['nullable', Rule::in(PatientNumberFormat::YEARS)],
            'patient_number_digits' => ['nullable', 'integer', 'min:'.PatientNumberFormat::MIN_DIGITS, 'max:'.PatientNumberFormat::MAX_DIGITS],
            'patient_number_separator' => ['nullable', Rule::in(PatientNumberFormat::SEPARATORS)],
            'patient_number_reset' => [
                'nullable',
                Rule::in(PatientNumberFormat::RESETS),
                // Sans année dans le numéro, une remise à 1 chaque année redonnerait les mêmes numéros.
                Rule::when(fn (Fluent $input) => $input->get('patient_number_year') === 'none', [Rule::notIn(['yearly'])]),
            ],
            'episode_number_digits' => ['nullable', 'integer', 'min:2', 'max:4'],
            'employee_number_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'employee_number_separator' => ['nullable', Rule::in(EmployeeNumberFormat::SEPARATORS)],
            'employee_number_digits' => ['nullable', 'integer', 'min:'.EmployeeNumberFormat::MIN_DIGITS, 'max:'.EmployeeNumberFormat::MAX_DIGITS],
            'currency_label' => ['required', Rule::in(AppSettings::CURRENCY_LABELS)],
            'currency_position' => ['required', Rule::in(['after', 'before'])],
            'currency_decimals' => ['required', 'integer', Rule::in([0, 2])],
            'baby_max_age' => ['required', 'integer', 'min:0', 'max:'.self::BABY_MAX_AGE_LIMIT],
            'child_max_age' => ['required', 'integer', 'gt:baby_max_age', 'max:'.self::CHILD_MAX_AGE_LIMIT],
            'director_name' => ['nullable', 'string', 'max:150'],
            'director_title' => ['nullable', 'string', 'max:150'],
            'legal_nif' => ['nullable', 'string', 'max:40'],
            'legal_stat' => ['nullable', 'string', 'max:40'],
            'legal_address' => ['nullable', 'string', 'max:255'],
            'legal_phone' => ['nullable', 'string', 'max:40'],
            'legal_email' => ['nullable', 'email', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            // ADR-192 — remise personnel : facultative, sans valeur par défaut (la remise VIP
            // se règle avec les seuils VIP, dans son module).
            ...DiscountRules::pair('staff_discount_type', 'staff_discount_value', required: false),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function coupon(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^\s*[A-Za-z0-9_-]+\s*$/'],
            'label' => ['nullable', 'string', 'max:150'],
            ...DiscountRules::pair('discount_type', 'discount_value'),
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public static function couponMessages(): array
    {
        return [
            'code.required' => 'Donnez un code au coupon.',
            'code.regex' => 'Le code ne contient que des lettres, des chiffres, « - » et « _ ».',
            'code.max' => 'Le code tient en 40 caractères au plus.',
            'valid_until.after_or_equal' => 'La fin de validité suit le début.',
            'max_uses.min' => 'Au moins une utilisation.',
            ...DiscountRules::messages('discount_type', 'discount_value'),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'app_tagline.max' => 'La devise tient en 150 caractères au plus.',
            'auth_template.enum' => 'Choisissez un modèle d’authentification proposé.',
            'profile_template.enum' => 'Choisissez un modèle de profil proposé.',
            'search_engines_hidden.boolean' => 'Cochez ou décochez la case des moteurs de recherche.',
            'primary_color.regex' => 'La couleur s’écrit au format #RRVVBB, par exemple #1F7A99.',
            'light_background.regex' => 'La couleur s’écrit au format #RRVVBB.',
            'light_foreground.regex' => 'La couleur s’écrit au format #RRVVBB.',
            'dark_primary_color.regex' => 'La couleur s’écrit au format #RRVVBB.',
            'dark_background.regex' => 'La couleur s’écrit au format #RRVVBB.',
            'dark_foreground.regex' => 'La couleur s’écrit au format #RRVVBB.',
            'theme_preset.in' => 'Choisissez un thème proposé.',
            'ui_font_size.in' => 'Choisissez une taille proposée.',
            'patient_number_prefix.regex' => 'Le préfixe ne contient que des lettres et des chiffres.',
            'patient_number_prefix.max' => 'Le préfixe tient en 12 caractères au plus.',
            'patient_number_digits.min' => 'Le compteur a au moins '.PatientNumberFormat::MIN_DIGITS.' chiffres.',
            'patient_number_digits.max' => 'Le compteur a au plus '.PatientNumberFormat::MAX_DIGITS.' chiffres.',
            'patient_number_reset.not_in' => 'Sans année dans le numéro, la remise à 1 chaque année redonnerait les mêmes numéros : choisissez une numérotation continue.',
            'employee_number_prefix.regex' => 'Le préfixe ne contient que des lettres et des chiffres.',
            'employee_number_prefix.max' => 'Le préfixe tient en 12 caractères au plus.',
            'employee_number_digits.min' => 'Le numéro a au moins '.EmployeeNumberFormat::MIN_DIGITS.' chiffres.',
            'employee_number_digits.max' => 'Le numéro a au plus '.EmployeeNumberFormat::MAX_DIGITS.' chiffres.',
            'currency_label.in' => 'Choisissez Ar, Ariary ou MGA.',
            'baby_max_age.max' => 'Un bébé a au plus '.self::BABY_MAX_AGE_LIMIT.' ans.',
            'child_max_age.gt' => 'L’âge maximal d’un enfant doit dépasser celui d’un bébé.',
            'child_max_age.max' => 'Un enfant a au plus '.self::CHILD_MAX_AGE_LIMIT.' ans.',
            'legal_email.email' => 'Adresse email invalide.',
            ...DiscountRules::messages('staff_discount_type', 'staff_discount_value'),
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function asset(string $kind): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.self::ASSET_MIMES[$kind], 'max:'.self::ASSET_MAX_KB[$kind]],
        ];
    }

    /** @return array<string, string> */
    public static function assetMessages(string $kind): array
    {
        $formats = strtoupper(str_replace(',', ', ', self::ASSET_MIMES[$kind]));

        return [
            'file.required' => 'Choisissez un fichier.',
            'file.mimes' => "Format accepté : {$formats}.",
            'file.max' => 'Le fichier dépasse '.self::ASSET_MAX_KB[$kind].' Ko.',
        ];
    }
}
