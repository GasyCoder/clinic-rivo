<?php

namespace App\Services\Billing;

use App\Enums\DiscountSource;
use App\Enums\DiscountType;
use App\Models\DiscountCoupon;
use App\Models\Invoice;
use App\Models\PatientDiscount;
use App\Models\PatientStaffLink;
use App\Services\Patient\PatientVipClassifier;
use App\Services\Settings\AppSettings;
use App\Support\Money;

/**
 * ADR-192 — les remises auxquelles une facture a droit, et celle que la Caisse
 * applique : **une seule, la plus avantageuse pour le patient**.
 *
 * Toutes se calculent sur la **part patient** — ce qui reste après la mutuelle et
 * la prise en charge Personnel (ADR-047, ADR-052). La mutuelle paie donc toujours
 * sa part contractuelle ; le personnel garde sa prise en charge et la remise ne
 * porte que sur ce qui reste à sa charge.
 *
 *   VIP       le patient est VIP sur ce site (ADR-133) et une remise est réglée avec ses seuils
 *   Personnel le patient est relié à un employé en poste et une remise est réglée
 *   Patient   une remise propre à ce patient est en vigueur (décision habilitée)
 *   Coupon    un code saisi à la Caisse, valable aujourd'hui
 *
 * Rien n'est déduit d'un nom : chaque droit se lit sur un fait enregistré.
 */
final class InvoiceDiscountResolver
{
    public function __construct(private readonly AppSettings $settings) {}

    /** La part patient avant remise, en unités mineures. */
    public static function baseMinor(Invoice $invoice): int
    {
        return max(0, Money::toMinor($invoice->subtotal_amount) - Money::toMinor($invoice->coverage_amount));
    }

    /**
     * Les remises auxquelles la facture a droit, de la plus avantageuse à la moins.
     *
     * @return list<array{source: DiscountSource, label: string, type: DiscountType, value: string, amount_minor: int, coupon: ?DiscountCoupon, patient_discount: ?PatientDiscount}>
     */
    public function offers(Invoice $invoice, ?DiscountCoupon $coupon = null): array
    {
        $base = self::baseMinor($invoice);
        $offers = [];

        $add = function (DiscountSource $source, string $label, DiscountType $type, string $value, ?DiscountCoupon $coupon = null, ?PatientDiscount $patientDiscount = null) use (&$offers, $base): void {
            $amount = $type->amountOn($base, $value);

            if ($amount > 0) {
                $offers[] = [
                    'source' => $source,
                    'label' => $label,
                    'type' => $type,
                    'value' => Money::normalize($value),
                    'amount_minor' => $amount,
                    'coupon' => $coupon,
                    'patient_discount' => $patientDiscount,
                ];
            }
        };

        if ($invoice->patient_id !== null) {
            $patientDiscount = PatientDiscount::query()
                ->where('patient_id', $invoice->patient_id)
                ->inForce()
                ->get()
                ->sortByDesc(fn (PatientDiscount $discount) => $discount->discount_type->amountOn($base, (string) $discount->discount_value))
                ->first();

            if ($patientDiscount !== null) {
                $add(DiscountSource::Patient, DiscountSource::Patient->label(), $patientDiscount->discount_type, (string) $patientDiscount->discount_value, patientDiscount: $patientDiscount);
            }

            if (($rule = $this->settings->staffDiscount()) !== null && $this->isStaff($invoice->patient_id)) {
                $add(DiscountSource::Staff, DiscountSource::Staff->label(), $rule['type'], $rule['value']);
            }

            $vip = PatientVipClassifier::current();

            if (($rule = $vip->discount()) !== null && $vip->isVip($invoice->patient_id)) {
                $add(DiscountSource::Vip, DiscountSource::Vip->label(), $rule['type'], $rule['value']);
            }
        }

        if ($coupon !== null && $coupon->unusableReason() === null) {
            $add(DiscountSource::Coupon, trim('Coupon '.$coupon->code.($coupon->label ? ' — '.$coupon->label : '')), $coupon->discount_type, (string) $coupon->discount_value, coupon: $coupon);
        }

        usort($offers, fn (array $a, array $b) => [$b['amount_minor'], $a['source']->rank()] <=> [$a['amount_minor'], $b['source']->rank()]);

        return $offers;
    }

    /** @return array{source: DiscountSource, label: string, type: DiscountType, value: string, amount_minor: int, coupon: ?DiscountCoupon, patient_discount: ?PatientDiscount}|null */
    public function best(Invoice $invoice, ?DiscountCoupon $coupon = null): ?array
    {
        return $this->offers($invoice, $coupon)[0] ?? null;
    }

    /** Un membre du personnel : relié (lien en cours) à un employé en poste, non archivé. */
    private function isStaff(int $patientId): bool
    {
        return PatientStaffLink::query()
            ->active()
            ->where('patient_id', $patientId)
            ->whereHas('employee', fn ($query) => $query->where('active', true))
            ->exists();
    }
}
