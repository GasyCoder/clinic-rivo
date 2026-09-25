<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\Settings\StoreAppSettingAssetAction;
use App\Actions\Settings\UpdateAppSettingsAction;
use App\Enums\AuthTemplate;
use App\Enums\ProfileTemplate;
use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use App\Services\Settings\AppSettingsPresenter;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\Settings\AppSettingsRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les paramètres de l'application, cible par cible (ADR-184) : chaque site, et
 * le portail pour lui-même. Un site ne se règle que par son API — le portail
 * n'ouvre jamais sa base (ADR-004, ADR-027) ; le portail, lui, écrit dans la
 * sienne, par les mêmes actions et les mêmes règles.
 */
class AppSettingsController extends Controller
{
    public const PORTAL = 'PORTAL';

    public function index(Request $request, PortalSiteApiClient $client, AppSettingsPresenter $presenter): Response
    {
        $portal = [
            'site' => ['code' => self::PORTAL, 'name' => 'Portail Super Admin'],
            'kind' => 'portal',
            'status' => 'ONLINE',
            'ok' => true,
            'message' => null,
            'data' => $presenter->payload(),
        ];

        $sites = collect($client->appSettingsForAllSites($request->user()))
            ->map(fn (array $site) => [...$site, 'kind' => 'site'])
            ->all();

        return Inertia::render('SuperAdmin/Settings/Index', [
            'targets' => [...$sites, $portal],
            'limits' => [
                'baby_max_age' => AppSettingsRules::BABY_MAX_AGE_LIMIT,
                'child_max_age' => AppSettingsRules::CHILD_MAX_AGE_LIMIT,
                'asset_max_kb' => AppSettingsRules::ASSET_MAX_KB,
                'asset_mimes' => AppSettingsRules::ASSET_MIMES,
            ],
            'currencyLabels' => AppSettings::CURRENCY_LABELS,
            'authTemplates' => collect(AuthTemplate::cases())->map(fn (AuthTemplate $template) => [
                'value' => $template->value,
                'label' => $template->label(),
                'uses_background' => $template->usesBackground(),
            ])->all(),
            'profileTemplates' => collect(ProfileTemplate::cases())->map(fn (ProfileTemplate $template) => [
                'value' => $template->value,
                'label' => $template->label(),
            ])->all(),
        ]);
    }

    public function update(Request $request, PortalSiteApiClient $client, UpdateAppSettingsAction $action): RedirectResponse
    {
        $target = $this->target($request);
        $validated = $request->validate(AppSettingsRules::settings(), AppSettingsRules::messages());

        if ($target === self::PORTAL) {
            $action->execute($validated, CatalogActor::fromUser($request->user()));

            return back()->with('status', 'Paramètres du portail enregistrés.');
        }

        return $this->relay($client->updateAppSettings($target, $validated, $request->user()), 'Paramètres enregistrés.');
    }

    public function storeAsset(Request $request, string $kind, PortalSiteApiClient $client, StoreAppSettingAssetAction $action): RedirectResponse
    {
        abort_unless(in_array($kind, AppSettings::ASSET_KINDS, true), 404);

        $target = $this->target($request);
        $request->validate(AppSettingsRules::asset($kind), AppSettingsRules::assetMessages($kind));

        if ($target === self::PORTAL) {
            $action->store($kind, $request->file('file'), CatalogActor::fromUser($request->user()));

            return back()->with('status', AppSettings::assetMessage($kind, 'enregistré', owner: ' du portail'));
        }

        return $this->relay(
            $client->storeAppSettingAsset($target, $kind, $request->file('file'), $request->user()),
            AppSettings::assetMessage($kind, 'enregistré'),
            'file',
        );
    }

    public function destroyAsset(Request $request, string $kind, PortalSiteApiClient $client, StoreAppSettingAssetAction $action): RedirectResponse
    {
        abort_unless(in_array($kind, AppSettings::ASSET_KINDS, true), 404);

        $target = $this->target($request);

        if ($target === self::PORTAL) {
            $action->remove($kind, CatalogActor::fromUser($request->user()));

            return back()->with('status', AppSettings::assetMessage($kind, 'retiré', owner: ' du portail'));
        }

        return $this->relay($client->deleteAppSettingAsset($target, $kind, $request->user()), AppSettings::assetMessage($kind, 'retiré'), 'file');
    }

    private function target(Request $request): string
    {
        $codes = [...collect(config('rivo.clinics', []))->pluck('code')->all(), self::PORTAL];

        return $request->validate(['site_code' => ['required', Rule::in($codes)]])['site_code'];
    }

    /** @param array<string, mixed> $result */
    private function relay(array $result, string $fallback, string $errorKey = 'site_code'): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: [$errorKey => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: $fallback);
    }
}
