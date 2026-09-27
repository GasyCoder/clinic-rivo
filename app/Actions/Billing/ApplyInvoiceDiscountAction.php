<?php

namespace App\Actions\Billing;

use App\Enums\DiscountSource;
use App\Enums\InvoiceStatus;
use App\Models\DiscountCoupon;
use App\Models\Invoice;
use App\Models\InvoiceDiscount;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Billing\InvoiceDiscountResolver;
use App\Services\Billing\InvoiceDiscountTotals;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 — la Caisse applique à une facture la remise la plus avantageuse à
 * laquelle elle a droit : une seule par facture. Le serveur la choisit et la
 * calcule ; le navigateur n'envoie qu'un éventuel code de coupon.
 *
 * Seulement tant que rien n'a été encaissé (même règle que l'ajout d'une ligne,
 * ADR-054) : une facture qui a reçu de l'argent ne change plus. La remise porte
 * son libellé comme motif, son auteur et sa date (CDC §34.2 règle 7).
 */
class ApplyInvoiceDiscountAction
{
    public const DISCOUNTABLE_STATUSES = [InvoiceStatus::Draft, InvoiceStatus::Validated];

    public function __construct(
        private readonly InvoiceDiscountResolver $resolver,
        private readonly Auditor $auditor,
    ) {}

    public function execute(Invoice $invoice, User $actor, ?string $couponCode = null): InvoiceDiscount
    {
        if ($actor->cannot('discounts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas appliquer de remise.');
        }

        return DB::transaction(function () use ($invoice, $actor, $couponCode): InvoiceDiscount {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            self::ensureDiscountable($invoice);

            if ($invoice->activeDiscount()->exists()) {
                throw ValidationException::withMessages(['discount' => 'Une remise est déjà appliquée à cette facture : retirez-la d’abord.']);
            }

            $coupon = null;
            $code = DiscountCoupon::normalizeCode($couponCode);

            if ($code !== '') {
                $coupon = DiscountCoupon::query()->where('code', $code)->lockForUpdate()->first();

                if ($coupon === null) {
                    throw ValidationException::withMessages(['coupon_code' => 'Aucun coupon ne porte ce code sur ce site.']);
                }

                if (($reason = $coupon->unusableReason()) !== null) {
                    throw ValidationException::withMessages(['coupon_code' => $reason]);
                }
            }

            $best = $this->resolver->best($invoice, $coupon);

            if ($best === null) {
                throw ValidationException::withMessages(['discount' => 'Aucune remise ne s’applique à cette facture.']);
            }

            $before = self::totals($invoice);

            $discount = $invoice->discounts()->create([
                'source' => $best['source'],
                'label' => $best['label'],
                'discount_type' => $best['type'],
                'discount_value' => $best['value'],
                'base_amount' => Money::fromMinor(InvoiceDiscountResolver::baseMinor($invoice)),
                'amount' => Money::fromMinor($best['amount_minor']),
                'discount_coupon_id' => $best['coupon']?->id,
                'patient_discount_id' => $best['patient_discount']?->id,
                'applied_by' => $actor->id,
                'applied_at' => now(),
                'active_key' => $invoice->id,
            ]);

            if ($best['source'] === DiscountSource::Coupon) {
                $best['coupon']->increment('uses_count');
            }

            InvoiceDiscountTotals::refresh($invoice);

            $this->auditor->record(
                'billing.discount.apply',
                entity: $invoice,
                oldValues: $before,
                newValues: [...self::totals($invoice), 'discount' => $best['label'], 'discount_value' => $best['type']->describe($best['value'])],
                module: 'billing',
                actor: $actor,
            );

            return $discount->refresh();
        });
    }

    /** Une remise ne se pose ou ne se retire que sur une facture qui n'a encore rien reçu. */
    public static function ensureDiscountable(Invoice $invoice, bool $removing = false): void
    {
        $statuses = $removing ? [...self::DISCOUNTABLE_STATUSES, InvoiceStatus::Covered] : self::DISCOUNTABLE_STATUSES;

        if (! in_array($invoice->status, $statuses, true)) {
            throw ValidationException::withMessages(['discount' => 'La remise ne se modifie que sur une facture brouillon ou à encaisser.']);
        }

        if (Money::toMinor($invoice->paid_amount) > 0) {
            throw ValidationException::withMessages(['discount' => 'Cette facture a déjà reçu un paiement : sa remise ne se modifie plus.']);
        }
    }

    /** @return array<string, string> */
    public static function totals(Invoice $invoice): array
    {
        return [
            'status' => $invoice->status->value,
            'discount_amount' => (string) $invoice->discount_amount,
            'total_amount' => (string) $invoice->total_amount,
            'balance_amount' => (string) $invoice->balance_amount,
        ];
    }
}
