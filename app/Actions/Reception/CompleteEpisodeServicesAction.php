<?php

namespace App\Actions\Reception;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Payment\RecordPaymentAction;
use App\Actions\Pharmacy\CreateExternalDispenseAction;
use App\DTOs\Reception\ArrivalRegistrationResult;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionCartKind;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\Invoice;
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
        private readonly CreateExternalDispenseAction $createPharmacySale,
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
        ?string $cashRegisterUuid = null,
    ): ArrivalRegistrationResult {
        if ($designationDeferred) {
            $episode = $this->planRouting->planUnknownNeed($episode, $actor);
            $episode->receptionJourneyDraft()->delete();

            return new ArrivalRegistrationResult($episode);
        }

        // ADR-104 — le panier porte deux rayons qui ne suivent pas le même
        // circuit. Les séparer ici, une fois, évite que chaque étape en
        // dessous ait à redemander de quel genre est une ligne.
        $serviceLines = array_values(array_filter(
            $catalogLines,
            fn (array $line) => ReceptionCartKind::fromLine($line) === ReceptionCartKind::Service,
        ));
        $medicineLines = array_values(array_filter(
            $catalogLines,
            fn (array $line) => ReceptionCartKind::fromLine($line) === ReceptionCartKind::Medicine,
        ));
        $pharmacyOnly = $serviceLines === [] && $medicineLines !== [];

        if ($serviceLines !== []) {
            $this->planRouting->execute($episode, $serviceLines, $actor);
        }

        $episode->receptionJourneyDraft()->delete();
        $episode = $episode->fresh(['patient', 'serviceRequests', 'orientations']);

        // Le ticket Pharmacie part avant la facture du passage, et
        // indépendamment d'elle : il réserve du stock, et c'est cette
        // réservation qui doit échouer tôt si la boîte n'est plus là.
        $pharmacy = $medicineLines === []
            ? ['invoice' => null, 'warning' => null]
            : $this->preparePharmacySale($episode, $medicineLines, $actor);
        $pharmacySale = $pharmacy['invoice'];

        if ($pharmacyOnly) {
            $this->settleWithoutClinicalRouting($episode);
            $episode = $episode->fresh(['patient']);

            return new ArrivalRegistrationResult(
                $episode,
                billingWarning: $pharmacy['warning'],
                pharmacyInvoice: $pharmacySale,
                pharmacyOnly: true,
            );
        }

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
                $serviceLines,
                $pharmacySale,
                $paymentChoice,
                $paymentMethodId,
                $paymentReference,
                $cashRegisterUuid,
            ): ArrivalRegistrationResult {
                $invoice = $this->createInvoice->execute($episode->patient, [
                    'episode_uuid' => $episode->uuid,
                    // Les médicaments ne rejoignent jamais la facture du
                    // passage : leur ticket est séparé (ADR-050).
                    'catalog_lines' => $serviceLines,
                ], $actor);
                $invoice = $this->validateInvoice->execute($invoice, $actor);

                if ($paymentChoice === ArrivalPaymentChoice::Later) {
                    return new ArrivalRegistrationResult($episode, $invoice, pharmacyInvoice: $pharmacySale);
                }

                if (Money::toMinor($invoice->balance_amount) === 0) {
                    return new ArrivalRegistrationResult($episode, $invoice, pharmacyInvoice: $pharmacySale);
                }

                $payment = $this->recordPayment->execute($episode->patient, [
                    'invoice_uuid' => $invoice->uuid,
                    'payment_method_id' => $paymentMethodId,
                    'amount' => $invoice->balance_amount,
                    'reference' => $paymentReference,
                    'notes' => "Encaissement à l’arrivée — passage {$episode->episode_number}",
                    'cash_register_uuid' => $cashRegisterUuid,
                ], $actor);

                return new ArrivalRegistrationResult(
                    $episode,
                    $payment->invoice,
                    $payment,
                    pharmacyInvoice: $pharmacySale,
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
    /**
     * Le rayon Pharmacie du panier devient une vente rattachée au passage :
     * réservation FEFO des lots puis ticket, par la même Action que la
     * vente comptoir qu'elle remplace (ADR-104). En écrire une seconde
     * dupliquerait un circuit FEFO testé, ce que l'ADR-098 refuse.
     *
     * L'échec ne fait jamais tomber le passage : une boîte manquante doit
     * se régulariser à la Pharmacie, pas effacer l'identité et l'épisode
     * déjà créés — même principe que l'ADR-054 pour un acte clinique.
     *
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $medicineLines
     * @return array{invoice: ?Invoice, warning: ?string}
     */
    private function preparePharmacySale(Episode $episode, array $medicineLines, User $actor): array
    {
        try {
            // Le panier désigne un `catalog_item` ; la Pharmacie travaille
            // sur la fiche médicament. La correspondance est lue en base,
            // jamais devinée depuis un code ou un libellé (ADR-052).
            $medicineUuids = CatalogItem::query()
                ->whereIn('uuid', array_column($medicineLines, 'catalog_item_uuid'))
                ->with('medicine:id,uuid,catalog_item_id')
                ->get()
                ->mapWithKeys(fn (CatalogItem $item) => [$item->uuid => $item->medicine?->uuid])
                ->filter();

            $lines = [];

            foreach ($medicineLines as $line) {
                $medicineUuid = $medicineUuids->get($line['catalog_item_uuid']);

                if ($medicineUuid === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'Un produit sélectionné n’existe plus au référentiel Pharmacie.',
                    ]);
                }

                $lines[] = [
                    'medicine_uuid' => $medicineUuid,
                    'quantity' => (int) round((float) $line['quantity']),
                ];
            }

            $dispense = $this->createPharmacySale->execute([
                'lines' => $lines,
                // Le ticket porte le nom du patient : c'est ce qui
                // permet de le retrouver à la Caisse (ADR-050) au même
                // titre que son numéro de passage.
                'customer_name' => $episode->patient
                    ? trim("{$episode->patient->last_name} {$episode->patient->first_name}")
                    : null,
                'customer_phone' => $episode->patient?->phone,
            ], $actor, $episode);

            return ['invoice' => $dispense->invoice, 'warning' => null];
        } catch (Throwable $exception) {
            report($exception);

            $warning = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : null;

            return [
                'invoice' => null,
                'warning' => $warning
                    ?? 'Le passage est conservé, mais le ticket Pharmacie doit être repris depuis la Pharmacie.',
            ];
        }
    }

    /**
     * Un passage venu uniquement pour des médicaments n'ouvre aucune file
     * clinique : personne ne le prendra en charge, et sans cette transition
     * il resterait `PENDING_ORIENTATION` indéfiniment — le trou exact que
     * l'ADR-090 a bouché pour les passages cliniques. Il rejoint donc
     * directement « Sorties & règlements », où la Réception le clôt une
     * fois le ticket encaissé.
     */
    private function settleWithoutClinicalRouting(Episode $episode): void
    {
        // Le panier ne contenait aucune prestation, mais le passage peut
        // tout de même porter des files cliniques : une requalification en
        // urgence ouvre Soins **et** Médecine (ADR-056). Le déclarer en
        // attente de règlement laisserait deux équipes devant un patient
        // que la Réception croit sorti — et figerait un mode financier
        // alors que des actes vont encore s'ajouter. Même vérification que
        // l'ADR-054 avant de faire avancer un statut administratif.
        $hasActiveClinicalQueue = $episode->orientations()
            ->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])
            ->exists();

        if ($hasActiveClinicalQueue) {
            return;
        }

        $updates = [
            'designation_deferred' => false,
            'service_plan_finalized_at' => now(),
            'administrative_status' => EpisodeAdministrativeStatus::PendingSettlement,
        ];

        // Un achat de médicaments n'a aucune couverture à résoudre : le
        // ticket Pharmacie est au tarif Sans mutuelle par construction
        // (ADR-104), donc le patient en supporte le montant. Laisser
        // `financial_mode` à `null` ferait apparaître le passage comme
        // « contexte financier à régulariser » (ADR-051) alors qu'il n'y a
        // rien à régulariser. Un choix déjà posé n'est jamais réécrit.
        if ($episode->financial_mode === null) {
            $updates['financial_mode'] = EpisodeFinancialMode::Self;
            $updates['financial_context_completed_at'] = now();
        }

        $episode->forceFill($updates)->save();
    }
}
