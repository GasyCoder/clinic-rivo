<?php

namespace App\Actions\Reception;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Payment\RecordPaymentAction;
use App\DTOs\Reception\ArrivalRegistrationResult;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\EpisodeFinancialMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\Episode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Finalizes the clinical request first, then attempts billing separately.
 * A cash problem can therefore never erase a route already sent to a care
 * team. This is especially important for emergencies, but is safer for all
 * arrivals.
 */
class CompleteEpisodeServicesAction
{
    public function __construct(
        private readonly PlanEpisodeRoutingAction $planRouting,
        private readonly CreateInvoiceAction $createInvoice,
        private readonly ValidateInvoiceAction $validateInvoice,
        private readonly RecordPaymentAction $recordPayment,
    ) {}

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $catalogLines
     */
    public function execute(
        Episode $episode,
        User $actor,
        array $catalogLines,
        bool $designationDeferred,
        ArrivalPaymentChoice $paymentChoice = ArrivalPaymentChoice::Later,
        ?int $paymentMethodId = null,
        ?string $paymentReference = null,
    ): ArrivalRegistrationResult {
        if ($designationDeferred) {
            $episode = $this->planRouting->planUnknownNeed($episode, $actor);
            $episode->receptionJourneyDraft()->delete();

            return new ArrivalRegistrationResult($episode);
        }

        $this->planRouting->execute($episode, $catalogLines, $actor);
        $episode->receptionJourneyDraft()->delete();
        $episode = $episode->fresh(['patient', 'serviceRequests', 'orientations']);

        if ($episode->financial_mode === null) {
            return new ArrivalRegistrationResult(
                $episode,
                billingWarning: 'Parcours clinique enregistré. Le contexte financier du passage doit être régularisé avant facturation.',
            );
        }

        if ($episode->financial_mode === EpisodeFinancialMode::Staff) {
            $hasUnclassifiedService = $episode->serviceRequests->contains(
                fn ($request) => $request->staff_coverage_policy === StaffCoveragePolicy::Unclassified,
            );

            if ($hasUnclassifiedService) {
                return new ArrivalRegistrationResult(
                    $episode,
                    billingWarning: 'Prestations enregistrées. Une politique Personnel reste à classifier ; la couverture Personnel doit être calculée par RH / Finance et la facturation demeure en attente.',
                );
            }
        }

        try {
            return DB::transaction(function () use (
                $episode,
                $actor,
                $catalogLines,
                $paymentChoice,
                $paymentMethodId,
                $paymentReference,
            ): ArrivalRegistrationResult {
                $invoice = $this->createInvoice->execute($episode->patient, [
                    'episode_uuid' => $episode->uuid,
                    'catalog_lines' => $catalogLines,
                ], $actor);
                $invoice = $this->validateInvoice->execute($invoice, $actor);

                if ($paymentChoice === ArrivalPaymentChoice::Later) {
                    return new ArrivalRegistrationResult($episode, $invoice);
                }

                if (Money::toMinor($invoice->balance_amount) === 0) {
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
            });
        } catch (Throwable $exception) {
            report($exception);

            $warning = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : null;

            return new ArrivalRegistrationResult(
                $episode,
                billingWarning: $warning
                    ?? 'Le parcours clinique est conservé, mais la facturation doit être reprise depuis le compte patient.',
            );
        }
    }
}
