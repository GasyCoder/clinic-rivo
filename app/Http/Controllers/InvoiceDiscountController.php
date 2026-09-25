<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyInvoiceDiscountAction;
use App\Actions\Billing\RemoveInvoiceDiscountAction;
use App\Enums\DiscountSource;
use App\Models\DiscountCoupon;
use App\Models\Invoice;
use App\Models\InvoiceDiscount;
use App\Services\Billing\InvoiceDiscountResolver;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-192 — la remise d'une facture, à la Caisse : ce à quoi elle a droit, la
 * plus avantageuse appliquée en un geste, retirée tant que rien n'est encaissé.
 */
class InvoiceDiscountController extends Controller
{
    /** Les remises possibles, la meilleure en tête ; un code de coupon se vérifie ici. */
    public function show(Request $request, Invoice $invoice, InvoiceDiscountResolver $resolver): JsonResponse
    {
        $code = DiscountCoupon::normalizeCode($request->query('coupon_code'));
        $coupon = $code !== '' ? DiscountCoupon::query()->where('code', $code)->first() : null;
        $couponError = match (true) {
            $code === '' => null,
            $coupon === null => 'Aucun coupon ne porte ce code sur ce site.',
            default => $coupon->unusableReason(),
        };

        $active = $invoice->activeDiscount()->with('applier:id,name')->first();
        $base = InvoiceDiscountResolver::baseMinor($invoice);

        return response()->json([
            'base_amount' => Money::fromMinor($base),
            'discountable' => in_array($invoice->status, ApplyInvoiceDiscountAction::DISCOUNTABLE_STATUSES, true)
                && Money::toMinor($invoice->paid_amount) === 0
                && $base > 0,
            'active' => $active ? $this->present($active) : null,
            'offers' => $active ? [] : collect($resolver->offers($invoice, $couponError === null ? $coupon : null))
                ->map(fn (array $offer) => [
                    'source' => $offer['source']->value,
                    'label' => $offer['label'],
                    'describe' => $offer['type']->describe($offer['value']),
                    'amount' => Money::fromMinor($offer['amount_minor']),
                    'total_after' => Money::fromMinor($base - $offer['amount_minor']),
                ])->values()->all(),
            'coupon_error' => $couponError,
        ]);
    }

    public function store(Request $request, Invoice $invoice, ApplyInvoiceDiscountAction $action): RedirectResponse
    {
        $validated = $request->validate(['coupon_code' => ['nullable', 'string', 'max:60']]);
        $discount = $action->execute($invoice, $request->user(), $validated['coupon_code'] ?? null);

        $message = "{$discount->label} appliquée : −".number_format((float) $discount->amount, 0, ',', "\u{202F}").' Ar.';

        if (filled($validated['coupon_code'] ?? null) && $discount->source !== DiscountSource::Coupon) {
            $message .= ' Elle est plus avantageuse que le coupon, qui n’est pas utilisé.';
        }

        return back()->with('status', $message);
    }

    public function destroy(Request $request, Invoice $invoice, RemoveInvoiceDiscountAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->execute($invoice, $request->user(), $validated['reason'] ?? null);

        return back()->with('status', 'Remise retirée : la facture revient à son montant.');
    }

    /** @return array<string, mixed> */
    private function present(InvoiceDiscount $discount): array
    {
        return [
            'uuid' => $discount->uuid,
            'source' => $discount->source->value,
            'label' => $discount->label,
            'describe' => $discount->discount_type->describe((string) $discount->discount_value),
            'amount' => (string) $discount->amount,
            'applied_at' => $discount->applied_at?->toIso8601String(),
            'applied_by' => $discount->applier?->name,
        ];
    }
}
