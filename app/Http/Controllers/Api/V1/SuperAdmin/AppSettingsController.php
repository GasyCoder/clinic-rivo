<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Discounts\ArchiveDiscountCouponAction;
use App\Actions\Discounts\CreateDiscountCouponAction;
use App\Actions\Discounts\DeleteDiscountCouponAction;
use App\Actions\Settings\LiftSiteMaintenanceAction;
use App\Actions\Settings\SetSiteMaintenanceAction;
use App\Actions\Settings\StoreAppSettingAssetAction;
use App\Actions\Settings\UpdateAppSettingsAction;
use App\Http\Controllers\Controller;
use App\Models\DiscountCoupon;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use App\Services\Settings\AppSettingsPresenter;
use App\Services\Settings\SiteMaintenanceState;
use App\Support\Settings\AppSettingsRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les paramètres de l'application de ce site (ADR-184). Chaque site a les siens
 * — nom, logo, couleur, écriture de l'Ariary, tranches d'âge, identité légale,
 * direction — et le portail ne les écrit jamais directement : chaque appel
 * arrive par l'API, avec l'identité du Super Administrateur, réautorisé ici
 * (ADR-004, ADR-025, ADR-027).
 */
class AppSettingsController extends Controller
{
    public function show(Request $request, AppSettingsPresenter $presenter): JsonResponse
    {
        $this->authorizeActor($request, 'settings.view');

        return response()->json(['data' => $presenter->payload()]);
    }

    public function update(Request $request, UpdateAppSettingsAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'settings.update');
        $validated = $request->validate(AppSettingsRules::settings(), AppSettingsRules::messages());

        $action->execute($validated, $actor);

        return response()->json([
            'message' => 'Paramètres de l’application enregistrés pour ce site.',
            'data' => $presenter->payload(),
        ]);
    }

    public function storeAsset(Request $request, string $kind, StoreAppSettingAssetAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        abort_unless(in_array($kind, AppSettings::ASSET_KINDS, true), 404);

        $actor = $this->authorizeActor($request, 'settings.update');
        $request->validate(AppSettingsRules::asset($kind), AppSettingsRules::assetMessages($kind));

        $action->store($kind, $request->file('file'), $actor);

        return response()->json([
            'message' => AppSettings::assetMessage($kind, 'enregistré', ' pour ce site'),
            'data' => $presenter->payload(),
        ]);
    }

    public function destroyAsset(Request $request, string $kind, StoreAppSettingAssetAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        abort_unless(in_array($kind, AppSettings::ASSET_KINDS, true), 404);

        $actor = $this->authorizeActor($request, 'settings.update');
        $action->remove($kind, $actor);

        return response()->json([
            'message' => AppSettings::assetMessage($kind, 'retiré', ' pour ce site'),
            'data' => $presenter->payload(),
        ]);
    }

    /** ADR-192 — un coupon de remise, créé sur ce site depuis le portail. */
    public function storeCoupon(Request $request, CreateDiscountCouponAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'discount_coupons.create');
        $validated = $request->validate(AppSettingsRules::coupon(), AppSettingsRules::couponMessages());

        $coupon = $action->execute($validated, $actor);

        return response()->json([
            'message' => "Coupon {$coupon->code} créé pour ce site.",
            'data' => $presenter->payload(),
        ], 201);
    }

    public function archiveCoupon(Request $request, DiscountCoupon $coupon, ArchiveDiscountCouponAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'discount_coupons.archive');
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Indiquez pourquoi ce coupon est archivé.', 'reason.min' => 'Le motif tient en 3 caractères au moins.'],
        );

        $action->execute($coupon, $validated['reason'], $actor);

        return response()->json([
            'message' => "Coupon {$coupon->code} archivé.",
            'data' => $presenter->payload(),
        ]);
    }

    /** ADR-192 — un coupon archivé qui n'a jamais servi, supprimé définitivement. */
    public function destroyCoupon(Request $request, DiscountCoupon $coupon, DeleteDiscountCouponAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, DeleteDiscountCouponAction::PERMISSION);
        $code = $coupon->code;

        $action->execute($coupon, $actor);

        return response()->json([
            'message' => "Coupon {$code} supprimé définitivement.",
            'data' => $presenter->payload(),
        ]);
    }

    /**
     * ADR-193 — mettre ce site en maintenance maintenant, la programmer, ou modifier
     * celle qui est en cours ou à venir. Ses propres droits, pas `settings.update` :
     * fermer un site n'est pas changer une couleur.
     */
    public function updateMaintenance(Request $request, SetSiteMaintenanceAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, SiteMaintenanceState::MANAGE_PERMISSION);
        $validated = $request->validate(AppSettingsRules::maintenance(), AppSettingsRules::maintenanceMessages());

        $maintenance = $action->execute($validated, $actor);

        return response()->json([
            'message' => $maintenance->isActive() ? 'Le site est en maintenance.' : 'Maintenance programmée pour ce site.',
            'data' => $presenter->payload(),
        ]);
    }

    public function liftMaintenance(Request $request, LiftSiteMaintenanceAction $action, AppSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, SiteMaintenanceState::MANAGE_PERMISSION);
        $validated = $request->validate(AppSettingsRules::maintenanceLift());

        $maintenance = $action->execute($validated['reason'] ?? null, $actor);

        return response()->json([
            'message' => $maintenance->starts_at->isFuture() ? 'Maintenance programmée annulée.' : 'Maintenance levée : le site est de nouveau ouvert.',
            'data' => $presenter->payload(),
        ]);
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
