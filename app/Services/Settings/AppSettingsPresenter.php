<?php

namespace App\Services\Settings;

use App\Actions\Settings\UpdateAppSettingsAction;

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
            ],
            'assets' => collect(AppSettings::ASSET_KINDS)->mapWithKeys(fn (string $kind) => [$kind => [
                'present' => $this->settings->assetExists($kind),
                'data_url' => $this->settings->assetPreviewDataUri($kind),
            ]])->all(),
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->external_updated_by_name ?? $setting?->updatedBy?->name,
        ];
    }
}
