<?php

namespace App\Support\Settings;

use App\Enums\AuthTemplate;
use App\Enums\ProfileTemplate;
use App\Services\Settings\AppSettings;
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
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
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
            'currency_label.in' => 'Choisissez Ar, Ariary ou MGA.',
            'baby_max_age.max' => 'Un bébé a au plus '.self::BABY_MAX_AGE_LIMIT.' ans.',
            'child_max_age.gt' => 'L’âge maximal d’un enfant doit dépasser celui d’un bébé.',
            'child_max_age.max' => 'Un enfant a au plus '.self::CHILD_MAX_AGE_LIMIT.' ans.',
            'legal_email.email' => 'Adresse email invalide.',
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
