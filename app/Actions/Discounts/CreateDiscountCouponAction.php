<?php

namespace App\Actions\Discounts;

use App\Models\DiscountCoupon;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 — créer un coupon sur ce site, depuis le portail. Son code est unique
 * pour toujours, archives comprises.
 */
class CreateDiscountCouponAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, CatalogActor $actor): DiscountCoupon
    {
        if ($actor->cannot('discount_coupons.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer de coupon.');
        }

        $code = DiscountCoupon::normalizeCode($data['code'] ?? '');

        return DB::transaction(function () use ($data, $code, $actor): DiscountCoupon {
            if (DiscountCoupon::query()->where('code', $code)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['code' => 'Ce code existe déjà sur ce site (un code qui a servi ne se réutilise pas).']);
            }

            $coupon = DiscountCoupon::create([
                'code' => $code,
                'label' => filled($data['label'] ?? null) ? trim((string) $data['label']) : null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'max_uses' => $data['max_uses'] ?? null,
                'created_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('created'));

            $this->auditor->record(
                'discount_coupon.create',
                entity: $coupon,
                newValues: [
                    'code' => $coupon->code,
                    'discount' => $coupon->discount_type->describe((string) $coupon->discount_value),
                    'max_uses' => $coupon->max_uses,
                ],
                module: 'billing',
            );

            return $coupon;
        });
    }
}
