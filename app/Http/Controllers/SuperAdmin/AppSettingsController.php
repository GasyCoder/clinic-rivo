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
use App\Support\Numbering\EmployeeNumberFormat;
use App\Support\Numbering\PatientNumberFormat;
use App\Support\Settings\AppSettingsRules;
use App\Support\Settings\ThemePresets;
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

    private const MAINTENANCE_PORTAL_REFUSAL = 'La maintenance se règle pour un site : tous les comptes du portail la traverseraient.';

    /**
     * ADR-191 — les modules des paramètres, chacun sa page. La même liste, dans le
     * même ordre, que `resources/js/utilities/settingsSections.js` (vérifié par test) ;
     * le premier s'ouvre quand on arrive sur « Paramètres ».
     */
    public const SECTIONS = ['identite', 'theme', 'avance', 'ecrans', 'numerotation', 'ages', 'monnaie', 'remises', 'legal', 'direction', 'visibilite', 'maintenance'];

    /**
     * La page d'un module. Sans module, le premier : comme dans les paramètres de
     * ChatGPT ou de Claude, « Paramètres » ouvre directement ses réglages.
     */
    public function index(Request $request, PortalSiteApiClient $client, AppSettingsPresenter $presenter, ?string $section = null): Response|RedirectResponse
    {
        if ($section === null) {
            return redirect()->route('super-admin.settings.section', ['section' => self::SECTIONS[0], ...$request->query()]);
        }

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
            'section' => $section,
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
            // ADR-191 — thèmes proposés et bornes de la numérotation : une seule source.
            'themePresets' => ThemePresets::all(),
            'numberingOptions' => [
                'separators' => PatientNumberFormat::SEPARATORS,
                'patient_digits' => [PatientNumberFormat::MIN_DIGITS, PatientNumberFormat::MAX_DIGITS],
                'employee_digits' => [EmployeeNumberFormat::MIN_DIGITS, EmployeeNumberFormat::MAX_DIGITS],
                'episode_digits' => [2, 4],
                'defaults' => ['patient' => PatientNumberFormat::DEFAULTS, 'employee' => EmployeeNumberFormat::DEFAULTS],
            ],
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

    /**
     * ADR-192 — un coupon se crée sur un site, jamais sur le portail : le portail
     * n'émet aucune facture. Le site revalide tout et garde l'identité du Super
     * Administrateur.
     */
    public function storeCoupon(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $target = $this->siteTarget($request);
        $validated = $request->validate(AppSettingsRules::coupon(), AppSettingsRules::couponMessages());

        return $this->relay($client->createDiscountCoupon($target, $validated, $request->user()), 'Coupon créé.', 'code');
    }

    public function archiveCoupon(Request $request, string $coupon, PortalSiteApiClient $client): RedirectResponse
    {
        $target = $this->siteTarget($request);
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Indiquez pourquoi ce coupon est archivé.', 'reason.min' => 'Le motif tient en 3 caractères au moins.'],
        );

        return $this->relay($client->archiveDiscountCoupon($target, $coupon, $validated['reason'], $request->user()), 'Coupon archivé.', 'reason');
    }

    /** ADR-192 — seul un coupon archivé qui n'a jamais servi se supprime ; le site le vérifie. */
    public function destroyCoupon(Request $request, string $coupon, PortalSiteApiClient $client): RedirectResponse
    {
        $target = $this->siteTarget($request);

        return $this->relay($client->deleteDiscountCoupon($target, $coupon, $request->user()), 'Coupon supprimé.', 'coupon');
    }

    /**
     * ADR-193 — la maintenance d'un site, jamais du portail : tous ses comptes sont
     * Super Administrateurs et la traverseraient. Le site revalide tout et garde
     * l'identité du Super Administrateur.
     */
    public function updateMaintenance(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $target = $this->siteTarget($request, self::MAINTENANCE_PORTAL_REFUSAL);
        $validated = $request->validate(AppSettingsRules::maintenance(), AppSettingsRules::maintenanceMessages());

        return $this->relay($client->updateSiteMaintenance($target, $validated, $request->user()), 'Maintenance enregistrée.', 'maintenance');
    }

    public function liftMaintenance(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $target = $this->siteTarget($request, self::MAINTENANCE_PORTAL_REFUSAL);
        $validated = $request->validate(AppSettingsRules::maintenanceLift());

        return $this->relay($client->liftSiteMaintenance($target, $validated['reason'] ?? null, $request->user()), 'Maintenance levée.', 'maintenance');
    }

    /** Un site, jamais le portail : les remises ne portent que sur les factures des sites, la maintenance que sur leurs comptes. */
    private function siteTarget(Request $request, string $portalRefusal = 'Les coupons se créent sur un site : le portail n’émet aucune facture.'): string
    {
        $codes = collect(config('rivo.clinics', []))->pluck('code')->all();

        return $request->validate(
            ['site_code' => ['required', Rule::in($codes)]],
            ['site_code.in' => $portalRefusal],
        )['site_code'];
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
