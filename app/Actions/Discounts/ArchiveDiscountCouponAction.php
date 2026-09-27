<?php

namespace App\Actions\Discounts;

use App\Models\DiscountCoupon;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 — archiver un coupon : il ne sert plus, mais reste lisible, et les
 * factures qui l'ont reçu gardent leur remise. Jamais supprimé.
 */
class ArchiveDiscountCouponAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(DiscountCoupon $coupon, string $reason, CatalogActor $actor): DiscountCoupon
    {
        if ($actor->cannot('discount_coupons.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver de coupon.');
        }

        return DB::transaction(function () use ($coupon, $reason, $actor): DiscountCoupon {
            $coupon = DiscountCoupon::query()->lockForUpdate()->findOrFail($coupon->id);

            if ($coupon->archived_at !== null) {
                throw ValidationException::withMessages(['coupon' => 'Ce coupon est déjà archivé.']);
            }

            $coupon->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor->localUserId(),
                'archive_reason' => trim($reason),
            ] + $actor->externalAttribution('archived'))->save();

            $this->auditor->record('discount_coupon.archive', entity: $coupon, reason: $coupon->archive_reason, module: 'billing');

            return $coupon->refresh();
        });
    }
}
