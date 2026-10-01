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
 * ADR-184 — dépose ou retire le logo, l'icône, la signature du directeur,
 * l'image de fond des pages d'authentification, l'emblème du badge (ADR-209) ou
 * le logo du compte rendu d'analyses (ADR-223).
 *
 * Les fichiers vivent sur le disque privé du site : le logo et l'icône sont
 * servis par une route qui ne sert qu'eux, la signature ne l'est jamais — elle
 * n'entre dans un document que copiée dedans, au moment où il est produit.
 * L'ancien fichier est supprimé après l'enregistrement du nouveau ; l'audit
 * garde le nom de chacun.
 */
class StoreAppSettingAssetAction
{
    private const BACKGROUND_WIDTH = 1600;

    private const BACKGROUND_QUALITY = 70;

    private const COLUMNS = ['logo' => 'logo_path', 'icon' => 'icon_path', 'signature' => 'signature_path', 'background' => 'auth_background_path', 'badge' => 'badge_logo_path', 'lab_logo' => 'lab_report_logo_path'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AppSettings $settings,
    ) {}

    public function store(string $kind, UploadedFile $file, CatalogActor $actor): AppSetting
    {
        $column = $this->column($kind);
        $this->authorize($actor);

        $reduced = $kind === 'background' ? $this->reducedBackground($file) : null;

        if ($reduced !== null) {
            $path = 'branding/'.$kind.'-'.Str::uuid().'.jpg';
            Storage::disk(AppSettings::DISK)->put($path, $reduced);
        } else {
            $path = $file->storeAs(
                'branding',
                $kind.'-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
                AppSettings::DISK,
            );
        }

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

    /**
     * L'image de fond des pages de connexion couvre l'écran sous un voile sombre :
     * une photo de 2 Mo s'y voit comme une photo de 200 Ko, mais se télécharge dix
     * fois plus lentement — depuis Madagascar, plusieurs secondes avant que la page
     * paraisse finie. Elle est ramenée à BACKGROUND_WIDTH px de large en JPEG
     * progressif. Sans GD, ou sur une image illisible, le fichier déposé est gardé
     * tel quel : rien n'est refusé pour une optimisation.
     */
    private function reducedBackground(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

            if ($image === false) {
                return null;
            }

            $scaled = imagesx($image) > self::BACKGROUND_WIDTH
                ? imagescale($image, self::BACKGROUND_WIDTH, -1, IMG_BICUBIC)
                : $image;

            // Un PNG transparent posé sur du blanc, jamais sur du noir par défaut.
            $canvas = imagecreatetruecolor(imagesx($scaled ?: $image), imagesy($scaled ?: $image));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $scaled ?: $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas));
            imageinterlace($canvas, true);

            ob_start();
            imagejpeg($canvas, null, self::BACKGROUND_QUALITY);

            return (string) ob_get_clean() ?: null;
        } catch (Throwable) {
            return null;
        }
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
