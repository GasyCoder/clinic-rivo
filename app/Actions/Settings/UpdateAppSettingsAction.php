<?php

namespace App\Actions\Settings;

use App\Models\AppSetting;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-184 — règle les paramètres de l'application sur ce déploiement.
 *
 * Une seule ligne, remplacée sur place. Rien n'est réécrit ailleurs : une
 * facture déjà émise garde ses montants, un patient déjà enregistré garde sa
 * civilité ; seul ce qui s'affiche — et ce que le formulaire patient demande —
 * change à partir de maintenant. L'audit garde l'ancienne et la nouvelle
 * valeur, avec l'identité du Super Administrateur central (ADR-027).
 */
class UpdateAppSettingsAction
{
    public const FIELDS = [
        'app_name', 'app_tagline', 'primary_color', 'search_engines_hidden',
        'auth_template', 'profile_template',
        'currency_label', 'currency_position', 'currency_decimals',
        'baby_max_age', 'child_max_age',
        'director_name', 'director_title',
        'legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account',
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AppSettings $settings,
    ) {}

    /** @param array<string, mixed> $data les champs validés, tous présents */
    public function execute(array $data, CatalogActor $actor): AppSetting
    {
        if ($actor->cannot('settings.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier les paramètres de l’application.');
        }

        $this->settings->ensureInstalled('site_code');

        $setting = DB::transaction(function () use ($data, $actor): AppSetting {
            $setting = AppSetting::query()->lockForUpdate()->first() ?? new AppSetting;
            $before = $setting->exists ? $this->snapshot($setting) : [];

            $values = [];

            foreach (self::FIELDS as $field) {
                $value = $data[$field] ?? null;
                $values[$field] = is_string($value) && trim($value) === '' ? null : $value;
            }

            $setting->fill([
                ...$values,
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'))->save();

            $this->auditor->record(
                'app_settings.update',
                entity: $setting,
                oldValues: $before,
                newValues: $this->snapshot($setting->fresh()),
                module: 'settings',
            );

            return $setting->fresh();
        });

        $this->settings->forget();

        return $setting;
    }

    /** @return array<string, mixed> */
    private function snapshot(AppSetting $setting): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => $setting->{$field}])->all();
    }
}
