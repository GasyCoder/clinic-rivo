<?php

namespace App\Actions\Reception;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Actions\Payment\RecordPaymentAction;
use App\DTOs\Reception\ArrivalRegistrationResult;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\EpisodePriority;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Reception use case: administrative arrival, optional
 * tariff-backed invoice, and optional immediate payment at the single cash
 * desk. The lower-level Actions retain their own business invariants.
 */
class CompletePatientArrivalAction
{
    public function __construct(
        private readonly RegisterArrivalAction $registerArrival,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly ValidateInvoiceAction $validateInvoice,
        private readonly RecordPaymentAction $recordPayment,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newPatientData
     * @param  array<string, mixed>|null  $existingPatientData
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $catalogLines
     */
    public function execute(
        User $actor,
        ?string $existingPatientUuid,
        ?array $newPatientData,
        ?array $existingPatientData = null,
        bool $confirmDuplicate = false,
        EpisodePriority $priority = EpisodePriority::Normal,
        array $catalogLines = [],
        ArrivalPaymentChoice $paymentChoice = ArrivalPaymentChoice::Later,
        ?int $paymentMethodId = null,
        ?string $paymentReference = null,
    ): ArrivalRegistrationResult {
        $register = function () use ($existingPatientUuid, $newPatientData, $existingPatientData, $confirmDuplicate, $priority): Episode {
            return $this->registerArrival->execute(
                existingPatientUuid: $existingPatientUuid,
                newPatientData: $newPatientData,
                existingPatientData: $existingPatientData,
                confirmDuplicate: $confirmDuplicate,
                priority: $priority,
            );
        };

        if ($priority === EpisodePriority::Emergency) {
            // ADR-021: administrative billing or cash failure must never
            // erase an urgent episode that has already been oriented.
            $episode = $register();

            try {
                return DB::transaction(fn () => $this->completeBilling(
                    $episode,
                    $actor,
                    $catalogLines,
                    $paymentChoice,
                    $paymentMethodId,
                    $paymentReference,
                ));
            } catch (ValidationException $exception) {
                $warning = collect($exception->errors())->flatten()->first()
                    ?? 'La partie financière devra être complétée depuis le compte patient.';

                return new ArrivalRegistrationResult(
                    $episode,
                    billingWarning: $warning,
                );
            }
        }

        return DB::transaction(function () use ($register, $actor, $catalogLines, $paymentChoice, $paymentMethodId, $paymentReference) {
            return $this->completeBilling(
                $register(),
                $actor,
                $catalogLines,
                $paymentChoice,
                $paymentMethodId,
                $paymentReference,
            );
        });
    }

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $catalogLines
     */
    private function completeBilling(
        Episode $episode,
        User $actor,
        array $catalogLines,
        ArrivalPaymentChoice $paymentChoice,
        ?int $paymentMethodId,
        ?string $paymentReference,
    ): ArrivalRegistrationResult {
        if ($catalogLines === []) {
            return new ArrivalRegistrationResult($episode);
        }

        $invoice = $this->createInvoice->execute($episode->patient, [
            'episode_uuid' => $episode->uuid,
            'catalog_lines' => $catalogLines,
        ], $actor);

        // An arrival invoice is immediately made official. "Pay later"
        // means VALIDATED with a balance due, never a receipt.
        $invoice = $this->validateInvoice->execute($invoice, $actor);

        if ($paymentChoice === ArrivalPaymentChoice::Later) {
            return new ArrivalRegistrationResult($episode, $invoice);
        }

        $payment = $this->recordPayment->execute($episode->patient, [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $paymentMethodId,
            'amount' => $invoice->balance_amount,
            'reference' => $paymentReference,
            'notes' => "Encaissement à l’arrivée — passage {$episode->episode_number}",
        ], $actor);

        return new ArrivalRegistrationResult(
            $episode,
            $payment->invoice,
            $payment,
        );
    }
}
