<?php

namespace App\Actions\Discounts;

use App\Models\Patient;
use App\Models\PatientDiscount;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-192 — accorder à un patient précis une remise durable. C'est une décision
 * d'une personne habilitée (`discounts.approve`), avec son motif, ses dates et son
 * auteur (CDC §34.2 règle 7) ; la Caisse l'applique ensuite si elle est la plus
 * avantageuse.
 */
class GrantPatientDiscountAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array{discount_type: string, discount_value: string|float, reason: string, valid_from?: ?string, valid_until?: ?string} $data */
    public function execute(Patient $patient, array $data, User $actor): PatientDiscount
    {
        if ($actor->cannot('discounts.approve')) {
            throw new AuthorizationException('Vous ne pouvez pas accorder de remise à un patient.');
        }

        return DB::transaction(function () use ($patient, $data, $actor): PatientDiscount {
            $discount = PatientDiscount::create([
                'patient_id' => $patient->id,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'reason' => trim($data['reason']),
                'valid_from' => $data['valid_from'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->auditor->record(
                'patient.discount.grant',
                entity: $patient,
                newValues: [
                    'discount' => $discount->discount_type->describe((string) $discount->discount_value),
                    'valid_from' => $discount->valid_from->toDateString(),
                    'valid_until' => $discount->valid_until?->toDateString(),
                ],
                reason: $discount->reason,
                module: 'billing',
                actor: $actor,
            );

            return $discount;
        });
    }
}
