<?php

namespace App\Actions\Discounts;

use App\Models\DiscountCoupon;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 (amendement du 2026-09-25) — supprimer définitivement un coupon archivé
 * qui n'a jamais servi. Exception étroite, comme l'ADR-062 pour un compte jamais
 * utilisé : rien ne cite ce coupon, sa suppression n'efface aucun historique.
 * Un coupon qui a servi reste archivé — la base le refuse de toute façon
 * (`invoice_discounts.discount_coupon_id`, restrictOnDelete).
 *
 * L'audit garde ce qu'était le coupon ; son code redevient libre.
 */
class DeleteDiscountCouponAction
{
    public const PERMISSION = 'discount_coupons.force_delete';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(DiscountCoupon $coupon, CatalogActor $actor): void
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Vous ne pouvez pas supprimer de coupon.');
        }

        DB::transaction(function () use ($coupon): void {
            $locked = DiscountCoupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();

            if (($blocker = $locked->deletionBlocker()) !== null) {
                throw ValidationException::withMessages(['coupon' => $blocker]);
            }

            $this->auditor->record(
                'discount_coupon.force_delete',
                entity: $locked,
                oldValues: [
                    'code' => $locked->code,
                    'label' => $locked->label,
                    'discount' => $locked->discount_type->describe((string) $locked->discount_value),
                    'archived_at' => $locked->archived_at?->toIso8601String(),
                    'archive_reason' => $locked->archive_reason,
                ],
                module: 'billing',
            );

            $locked->delete();
        });
    }
}
