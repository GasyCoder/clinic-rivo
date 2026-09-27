<?php

namespace App\Actions\Discounts;

use App\Models\PatientDiscount;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-192 — annuler la remise d'un patient, avec un motif. Elle reste lisible ;
 * les factures qui l'ont déjà reçue gardent leur remise (un instantané).
 */
class CancelPatientDiscountAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(PatientDiscount $discount, string $reason, User $actor): PatientDiscount
    {
        if ($actor->cannot('discounts.approve')) {
            throw new AuthorizationException('Vous ne pouvez pas annuler la remise d’un patient.');
        }

        return DB::transaction(function () use ($discount, $reason, $actor): PatientDiscount {
            $discount = PatientDiscount::query()->lockForUpdate()->findOrFail($discount->id);

            if ($discount->cancelled_at !== null) {
                throw ValidationException::withMessages(['discount' => 'Cette remise est déjà annulée.']);
            }

            $discount->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => trim($reason),
            ])->save();

            $this->auditor->record(
                'patient.discount.cancel',
                entity: $discount->patient,
                oldValues: ['discount' => $discount->discount_type->describe((string) $discount->discount_value)],
                reason: $discount->cancel_reason,
                module: 'billing',
                actor: $actor,
            );

            return $discount->refresh();
        });
    }
}
