<?php

namespace App\Actions\Settings;

use App\Models\AppSetting;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * ADR-184 — dépose ou retire le logo, l'icône, la signature du directeur ou
 * l'image de fond des pages d'authentification.
 *
 * Les fichiers vivent sur le disque privé du site : le logo et l'icône sont
 * servis par une route qui ne sert qu'eux, la signature ne l'est jamais — elle
 * n'entre dans un document que copiée dedans, au moment où il est produit.
 * L'ancien fichier est supprimé après l'enregistrement du nouveau ; l'audit
 * garde le nom de chacun.
 */
class StoreAppSettingAssetAction
{
    private const COLUMNS = ['logo' => 'logo_path', 'icon' => 'icon_path', 'signature' => 'signature_path', 'background' => 'auth_background_path'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AppSettings $settings,
    ) {}

    public function store(string $kind, UploadedFile $file, CatalogActor $actor): AppSetting
    {
        $column = $this->column($kind);
        $this->authorize($actor);

        $path = $file->storeAs(
            'branding',
            $kind.'-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
            AppSettings::DISK,
        );

        try {
            return $this->replace($kind, $column, $path, $actor, 'app_settings.asset.update');
        } catch (Throwable $exception) {
            // Rien d'enregistré : le fichier déposé ne doit pas rester orphelin.
            Storage::disk(AppSettings::DISK)->delete($path);

            throw $exception;
        }
    }

    public function remove(string $kind, CatalogActor $actor): AppSetting
    {
        $column = $this->column($kind);
        $this->authorize($actor);

        return $this->replace($kind, $column, null, $actor, 'app_settings.asset.remove');
    }

    private function replace(string $kind, string $column, ?string $path, CatalogActor $actor, string $action): AppSetting
    {
        [$setting, $previous] = DB::transaction(function () use ($kind, $column, $path, $actor, $action): array {
            $setting = AppSetting::query()->lockForUpdate()->first() ?? new AppSetting;
            $previous = $setting->{$column};

            $setting->fill([
                $column => $path,
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'))->save();

            $this->auditor->record(
                $action,
                entity: $setting,
                oldValues: [$kind => $previous],
                newValues: [$kind => $path],
                module: 'settings',
            );

            return [$setting->fresh(), $previous];
        });

        if ($previous && $previous !== $path) {
            Storage::disk(AppSettings::DISK)->delete($previous);
        }

        $this->settings->forget();

        return $setting;
    }

    private function column(string $kind): string
    {
        return self::COLUMNS[$kind] ?? throw new InvalidArgumentException("Type de fichier inconnu : {$kind}.");
    }

    private function authorize(CatalogActor $actor): void
    {
        if ($actor->cannot('settings.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier les paramètres de l’application.');
        }

        $this->settings->ensureInstalled('file');
    }
}
