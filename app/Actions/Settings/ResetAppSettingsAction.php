<?php

namespace App\Actions\Settings;

use App\Models\AppSetting;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-184 — rend tous les paramètres de l'application aux valeurs du déploiement.
 *
 * Les coupons de remise et la maintenance sont des données opérationnelles
 * distinctes : ils ne sont jamais modifiés par cette action. Les fichiers sont
 * supprimés après la transaction afin qu'un échec ne laisse pas la base pointer
 * vers un fichier disparu.
 */
class ResetAppSettingsAction
{
    public const CONFIRMATION = 'RÉINITIALISER';

    private const ASSETS = [
        'logo_path',
        'icon_path',
        'signature_path',
        'auth_background_path',
        'badge_logo_path',
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AppSettings $settings,
    ) {}

    public function execute(CatalogActor $actor): ?AppSetting
    {
        if ($actor->cannot('settings.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier les paramètres de l’application.');
        }

        $this->settings->ensureInstalled('site_code');

        $files = DB::transaction(function () use ($actor): array {
            $setting = AppSetting::query()->lockForUpdate()->first();

            if (! $setting) {
                return [];
            }

            $fields = [...UpdateAppSettingsAction::FIELDS, ...self::ASSETS];
            $before = $this->snapshot($setting, $fields);

            $this->auditor->record(
                'app_settings.reset',
                entity: $setting,
                oldValues: $before,
                newValues: [
                    'source' => 'deployment_defaults',
                    'reset_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('reset'),
                ],
                module: 'settings',
            );

            $files = array_values(array_filter(
                collect(self::ASSETS)->map(fn (string $field) => $before[$field])->all(),
            ));

            // L'absence de personnalisation est la source de vérité du repli
            // vers config/rivo.php, y compris pour de futurs réglages.
            $setting->delete();

            return $files;
        });

        foreach ($files as $path) {
            Storage::disk(AppSettings::DISK)->delete($path);
        }

        $this->settings->forget();

        return null;
    }

    /** @param array<int, string> $fields
     * @return array<string, mixed> */
    private function snapshot(AppSetting $setting, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $field) => [$field => $setting->{$field}])->all();
    }
}
