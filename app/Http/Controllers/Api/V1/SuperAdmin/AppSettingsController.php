<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Discounts\ArchiveDiscountCouponAction;
use App\Actions\Discounts\CreateDiscountCouponAction;
use App\Actions\Settings\StoreAppSettingAssetAction;
use App\Actions\Settings\UpdateAppSettingsAction;
use App\Http\Controllers\Controller;
use App\Models\DiscountCoupon;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\AppSettings;
use App\Services\Settings\AppSettingsPresenter;
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

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
