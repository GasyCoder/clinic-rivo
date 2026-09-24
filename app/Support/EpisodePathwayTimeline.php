<?php

namespace App\Support;

use App\Enums\AdministrativeExitType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Enums\PrescriptionStatus;
use App\Enums\ReceptionNextStep;
use App\Models\CareOrder;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeReceptionNextStep;
use App\Models\EpisodeServiceRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PharmacyDispense;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ADR-117 — le parcours d'un passage, de la Réception à la sortie, écrit une
 * seule fois.
 *
 * Le dossier d'un patient (frise compacte) et le « Détail du passage »
 * (liste détaillée) racontaient chacun le parcours à partir des seules
 * orientations, chacun à sa façon. Deux défauts en résultaient :
 *
 *   - deux orientations vers le même service (« Soins », « Soins ») se
 *     lisaient comme un doublon alors que ce sont deux demandes distinctes du
 *     médecin, avec chacune sa suite décidée (retour en Médecine, ou sortie
 *     directe) — information que rien n'affichait ;
 *   - la Réception, la Pharmacie et la Caisse — qui font pourtant partie du
 *     parcours du patient — n'y figuraient pas.
 *
 * Ce service compose donc une chronologie unique, déjà mise en mots, que les
 * deux écrans se contentent d'afficher : aucune règle n'est recopiée côté Vue,
 * et deux écrans ne peuvent plus raconter deux histoires différentes.
 *
 * Chaque section reste gardée par la permission qui possède réellement sa
 * donnée (ADR-054) : la Pharmacie exige `pharmacy.view` — ou `prescriptions.view`
 * pour l'ordonnance que l'on a soi-même prescrite —, les factures
 * `billing.view`, les paiements `payments.view`, les actes demandés aux Soins
 * `care_orders.view`. Sans le droit, l'étape n'est pas servie — jamais servie
 * vide, ce qui se lirait « rien n'a eu lieu ».
 *
 * Rien n'est inventé : chaque étape sort d'un enregistrement existant. Le seul
 * élément qui n'est pas un fait passé est l'étape « Sortie » à prononcer,
 * affichée quand la clôture est réellement attendue de la Réception (ADR-090).
 */
final class EpisodePathwayTimeline
{
    /** Ordre de présentation à horodatage égal : le parcours se lit dans le sens du patient. */
    private const RANK = [
        'RECEPTION' => 0,
        'ORIENTATION' => 1,
        'PHARMACY' => 2,
        'INVOICE' => 3,
        'PAYMENT' => 4,
        'EXIT' => 5,
    ];

    /** @return array<int, array<string, mixed>> */
    public function forEpisode(Episode $episode, User $user): array
    {
        return $this->forEpisodes(collect([$episode]), $user)[$episode->getKey()] ?? [];
    }

    /**
     * Compose plusieurs passages en un nombre constant de requêtes : le
     * dossier d'un patient en liste souvent plusieurs, et une requête par
     * passage ferait dépendre le temps de l'écran de l'historique du patient.
     *
     * @param  Collection<int, Episode>  $episodes
     * @return array<int, array<int, array<string, mixed>>> étapes, par identifiant de passage
     */
    public function forEpisodes(Collection $episodes, User $user): array
    {
        $ids = $episodes->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $canSeeCareOrderItems = $user->can('care_orders.view');
        $canSeePharmacy = $user->can('pharmacy.view');
        $canSeePrescriptions = $user->can('prescriptions.view');
        $canSeeBilling = $user->can('billing.view');
        $canSeePayments = $canSeeBilling && $user->can('payments.view');

        // Relues ici plutôt que lues sur les modèles reçus : le dossier d'un
        // patient charge les orientations avec quelques colonnes seulement, et
        // ce service ne doit pas dépendre de ce que l'appelant a eu la bonne
        // idée de sélectionner.
        $orientations = EpisodeOrientation::query()
            ->whereIn('episode_id', $ids)
            ->orderBy('oriented_at')
            ->orderBy('id')
            ->get()
            ->groupBy('episode_id');

        $careOrders = CareOrder::query()
            ->whereIn('episode_id', $ids)
            ->whereNotNull('care_orientation_id')
            ->when($canSeeCareOrderItems, fn ($query) => $query->with('items.careRecordProcedures'))
            ->orderBy('id')
            ->get()
            // Groupées, jamais indexées : `CreateEpisodeOrientationAction`
            // réutilise l'orientation Soins encore active, si bien que deux
            // demandes faites avant que les Soins aient terminé partagent la
            // même orientation — en indexer une seule perdrait l'autre.
            ->groupBy('care_orientation_id');

        $needs = EpisodeServiceRequest::query()
            ->whereIn('episode_id', $ids)
            ->orderBy('id')
            ->get(['episode_id', 'designation', 'quantity'])
            ->groupBy('episode_id');

        // ADR-177 — la suggestion de la Réception, lue comme telle : une
        // indication, jamais une étape du parcours.
        $nextSteps = EpisodeReceptionNextStep::query()
            ->whereIn('episode_id', $ids)
            ->get(['episode_id', 'module'])
            ->groupBy('episode_id');

        // Les ordonnances, pour qui peut les voir : le médecin qui a prescrit
        // suit ce qu'elle devient à la Pharmacie sans avoir à en détenir le
        // droit — c'est son ordonnance, pas le stock ni la caisse de la Pharmacie.
        $prescriptions = $canSeePrescriptions
            ? Prescription::query()
                ->whereIn('episode_id', $ids)
                ->with(['lines:id,prescription_id'])
                ->orderBy('prescribed_at')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Prescription $prescription) => $prescription->episode_id)
            : collect();

        $dispenses = $canSeePharmacy || $canSeePrescriptions
            ? PharmacyDispense::query()->whereIn('episode_id', $ids)->orderBy('requested_at')->orderBy('id')->get()->groupBy('episode_id')
            : collect();

        $invoices = $canSeeBilling
            ? Invoice::query()
                ->whereIn('episode_id', $ids)
                ->where('status', '!=', InvoiceStatus::Cancelled->value)
                ->with('pharmacyDispense:id,invoice_id')
                ->orderBy('created_at')
                ->get()
                ->groupBy('episode_id')
            : collect();

        $payments = $canSeePayments
            ? Payment::query()
                ->whereHas('invoice', fn ($query) => $query->whereIn('episode_id', $ids))
                ->with(['invoice:id,episode_id', 'invoice.pharmacyDispense:id,invoice_id', 'method:id,name'])
                ->orderBy('paid_at')
                ->get()
                ->groupBy(fn (Payment $payment) => $payment->invoice->episode_id)
            : collect();

        $names = $this->userNames($episodes, $careOrders, $dispenses, $payments, $prescriptions);

        $timelines = [];

        foreach ($episodes as $episode) {
            $steps = [];

            $steps[] = $this->receptionStep($episode, $needs->get($episode->getKey(), collect()), $nextSteps->get($episode->getKey(), collect()), $names);

            foreach ($this->orientationSteps($orientations->get($episode->getKey(), collect()), $careOrders, $canSeeCareOrderItems, $names) as $step) {
                $steps[] = $step;
            }

            $episodeDispenses = $dispenses->get($episode->getKey(), collect());
            $claimed = [];

            // Une ordonnance et la dispensation qu'elle a déclenchée sont un
            // seul passage à la Pharmacie : une seule étape, pas deux.
            foreach ($prescriptions->get($episode->getKey(), collect()) as $prescription) {
                $dispense = $episodeDispenses->firstWhere('prescription_id', $prescription->getKey());

                if ($dispense !== null) {
                    $claimed[] = $dispense->getKey();
                }

                $steps[] = $this->prescriptionStep($prescription, $dispense, $canSeePharmacy, $names);
            }

            // Les autres dispensations (achat au comptoir, ordonnance dont on
            // ne détient pas le droit) sont l'affaire de la Pharmacie.
            if ($canSeePharmacy) {
                foreach ($episodeDispenses as $dispense) {
                    if (! in_array($dispense->getKey(), $claimed, true)) {
                        $steps[] = $this->pharmacyStep($dispense, $names);
                    }
                }
            }

            foreach ($invoices->get($episode->getKey(), collect()) as $invoice) {
                $steps[] = $this->invoiceStep($invoice);
            }

            foreach ($payments->get($episode->getKey(), collect()) as $payment) {
                $steps[] = $this->paymentStep($payment, $names);
            }

            $exit = $this->exitStep($episode, $canSeeBilling, $names);

            if ($exit !== null) {
                $steps[] = $exit;
            }

            $timelines[$episode->getKey()] = $this->chronological($steps);
        }

        return $timelines;
    }

    /** @return array<string, mixed> */
    private function receptionStep(Episode $episode, Collection $needs, Collection $nextSteps, Collection $names): array
    {
        $notes = [];

        if ($episode->created_by !== null && $names->has($episode->created_by)) {
            $notes[] = 'Enregistré par '.$names->get($episode->created_by);
        }

        if ($needs->isNotEmpty()) {
            $notes[] = 'Besoin : '.$needs
                ->map(fn ($need) => $this->quantity($need->quantity) > 1
                    ? $need->designation.' ×'.$this->quantity($need->quantity)
                    : $need->designation)
                ->implode(', ');
        }

        if ($nextSteps->isNotEmpty()) {
            $labels = array_map(
                fn (string $value): string => ReceptionNextStep::from($value)->label(),
                array_values(array_intersect(
                    ReceptionNextStep::values(),
                    $nextSteps->map(fn (EpisodeReceptionNextStep $step): string => $step->module->value)->all(),
                )),
            );
            $notes[] = 'Prochaine étape suggérée : '.implode(', ', $labels);
        }

        if ($episode->priority === EpisodePriority::Emergency) {
            $notes[] = 'Passage classé urgence';
        }

        return $this->step([
            'key' => 'reception',
            'type' => 'RECEPTION',
            'module' => CatalogModule::Reception->value,
            'label' => CatalogModule::Reception->label(),
            'state' => 'DONE',
            'state_label' => 'Arrivée enregistrée',
            'at' => $episode->started_at ?? $episode->created_at,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @param  Collection<int, Collection<int, CareOrder>>  $careOrders  par identifiant d'orientation Soins
     * @return array<int, array<string, mixed>>
     */
    private function orientationSteps(Collection $orientations, Collection $careOrders, bool $withItems, Collection $names): array
    {
        $perModule = $orientations->groupBy(fn (EpisodeOrientation $orientation) => $orientation->destination_module->value);

        return $orientations->map(function (EpisodeOrientation $orientation) use ($orientations, $perModule, $careOrders, $withItems, $names) {
            $siblings = $perModule->get($orientation->destination_module->value);
            $total = $siblings->count();
            $sequence = $siblings->values()->search(fn (EpisodeOrientation $sibling) => $sibling->is($orientation)) + 1;
            $orders = $careOrders->get($orientation->getKey(), collect());

            $requesters = $orders
                ->pluck('requested_by')
                ->unique()
                ->map(fn ($id) => $names->get($id))
                ->filter()
                ->values();

            return $this->step([
                'key' => 'orientation-'.$orientation->uuid,
                'type' => 'ORIENTATION',
                'module' => $orientation->destination_module->value,
                'label' => $orientation->destination_module->label(),
                'from_label' => $orientation->source_module?->label(),
                'sequence' => $total > 1 ? $sequence : null,
                'sequence_total' => $total > 1 ? $total : null,
                'qualifier' => $total > 1 ? $this->ordinal($sequence).($orders->isNotEmpty() ? ' demande' : ' orientation') : null,
                'state' => match ($orientation->status) {
                    EpisodeOrientationStatus::Pending => 'PENDING',
                    EpisodeOrientationStatus::InProgress => 'ACTIVE',
                    EpisodeOrientationStatus::Completed => 'DONE',
                    EpisodeOrientationStatus::Cancelled => 'CANCELLED',
                },
                'state_label' => $orientation->status->label(),
                'at' => $orientation->oriented_at,
                'accepted_at' => $orientation->accepted_at,
                'completed_at' => $orientation->completed_at,
                'notes' => array_values(array_filter([
                    $requesters->isEmpty() ? null : 'Demandé par '.$requesters->implode(', '),
                    // ADR-166 — une suite changée aux Soins : la Réception et le
                    // médecin lisent ici pourquoi le parcours prévu n'a pas été suivi.
                    match ($orientation->offPlanOutcome($orientations)) {
                        'FINISH' => 'Terminé aux Soins, sans passer en Médecine — motif : '.$orientation->completion_reason,
                        'MEDICINE' => 'Transmis au médecin, hors du parcours prévu — motif : '.$orientation->completion_reason,
                        default => null,
                    },
                ])),
                'follow_up' => $orders->isEmpty() ? null : CareRequestSummary::followUp($orders),
                'items' => $orders->isNotEmpty() && $withItems ? CareRequestSummary::items($orders) : [],
            ]);
        })->all();
    }

    /**
     * L'étape d'une ordonnance, avec ce que la Pharmacie en a fait.
     *
     * Le médecin voit son ordonnance dans le passage ; le parcours qui la
     * taisait laissait croire qu'elle n'était jamais partie. Seule la
     * dispensation que la Pharmacie a réellement reçue en fait une étape de
     * la Pharmacie : une ordonnance faite de lignes hors référentiel ne crée
     * aucune demande de dispensation (`CreateInternalDispenseRequestAction`) et
     * le dit, plutôt que d'annoncer un passage qui n'a pas eu lieu.
     *
     * Le détail du règlement (« en attente de règlement », « prête à
     * délivrer ») appartient à la Pharmacie : sans `pharmacy.view`, le
     * prescripteur lit seulement l'issue qui le concerne — transmise,
     * délivrée, annulée.
     *
     * @return array<string, mixed>
     */
    private function prescriptionStep(Prescription $prescription, ?PharmacyDispense $dispense, bool $canSeePharmacy, Collection $names): array
    {
        $count = $prescription->lines->count();
        $notes = array_values(array_filter([
            $prescription->prescribed_by !== null && $names->has($prescription->prescribed_by)
                ? 'Prescrite par '.$names->get($prescription->prescribed_by)
                : null,
            $count > 0 ? $count.' médicament'.($count > 1 ? 's' : '') : null,
        ]));

        if ($prescription->status === PrescriptionStatus::Cancelled) {
            [$state, $label] = ['CANCELLED', 'Ordonnance annulée'];
        } elseif ($dispense === null) {
            [$state, $label] = ['DONE', 'Établie'];
            $notes[] = 'Hors référentiel : aucune demande de dispensation à la Pharmacie.';
        } elseif ($canSeePharmacy) {
            [$state, $label] = [$this->dispenseState($dispense), $dispense->status->label()];
        } else {
            [$state, $label] = match ($dispense->status) {
                PharmacyDispenseStatus::Dispensed => ['DONE', 'Délivrée'],
                PharmacyDispenseStatus::PartiallyDispensed => ['ACTIVE', 'Délivrée partiellement'],
                PharmacyDispenseStatus::Cancelled => ['CANCELLED', 'Annulée'],
                default => ['ACTIVE', 'Transmise à la Pharmacie'],
            };
        }

        return $this->step([
            'key' => 'prescription-'.$prescription->uuid,
            'type' => 'PHARMACY',
            'module' => CatalogModule::Pharmacy->value,
            'label' => $dispense === null ? 'Ordonnance' : CatalogModule::Pharmacy->label(),
            'from_label' => $dispense === null ? null : CatalogModule::Medicine->label(),
            'qualifier' => $dispense === null ? null : 'Ordonnance',
            'state' => $state,
            'state_label' => $label,
            'at' => $prescription->prescribed_at ?? $prescription->created_at,
            'completed_at' => $dispense?->completed_at,
            'notes' => $notes,
        ]);
    }

    /** @return array<string, mixed> */
    private function pharmacyStep(PharmacyDispense $dispense, Collection $names): array
    {
        $notes = [];

        if ($dispense->requested_by !== null && $names->has($dispense->requested_by)) {
            $notes[] = 'Demandé par '.$names->get($dispense->requested_by);
        }

        return $this->step([
            'key' => 'pharmacy-'.$dispense->uuid,
            'type' => 'PHARMACY',
            'module' => CatalogModule::Pharmacy->value,
            'label' => CatalogModule::Pharmacy->label(),
            'from_label' => $dispense->type === PharmacyDispenseType::Internal ? CatalogModule::Medicine->label() : null,
            'qualifier' => $dispense->type === PharmacyDispenseType::External ? 'Achat au comptoir' : 'Ordonnance',
            'state' => $this->dispenseState($dispense),
            'state_label' => $dispense->status->label(),
            'at' => $dispense->requested_at ?? $dispense->created_at,
            'completed_at' => $dispense->completed_at,
            'notes' => $notes,
        ]);
    }

    private function dispenseState(PharmacyDispense $dispense): string
    {
        return match ($dispense->status) {
            PharmacyDispenseStatus::Dispensed => 'DONE',
            PharmacyDispenseStatus::Cancelled => 'CANCELLED',
            PharmacyDispenseStatus::Ready, PharmacyDispenseStatus::PartiallyDispensed => 'ACTIVE',
            PharmacyDispenseStatus::AwaitingInvoice, PharmacyDispenseStatus::AwaitingPayment => 'PENDING',
        };
    }

    /** @return array<string, mixed> */
    private function invoiceStep(Invoice $invoice): array
    {
        $balance = Money::toMinor($invoice->balance_amount);

        return $this->step([
            'key' => 'invoice-'.$invoice->uuid,
            'type' => 'INVOICE',
            'module' => null,
            'label' => 'Facture',
            'qualifier' => $invoice->invoice_number,
            'state' => match ($invoice->status) {
                InvoiceStatus::Paid, InvoiceStatus::Covered => 'DONE',
                InvoiceStatus::PartiallyPaid => 'ACTIVE',
                default => 'PENDING',
            },
            'state_label' => $invoice->status->label(),
            'at' => $invoice->validated_at ?? $invoice->created_at,
            // La Caisse encaisse le ticket de la Pharmacie sans détenir le droit
            // de voir la Pharmacie : sans cette mention, une facture réglée
            // après la sortie ne se rattache à rien.
            'notes' => $invoice->pharmacyDispense !== null ? ['Ticket Pharmacie'] : [],
            // Le reste dû, jamais « total − payé » recalculé ici : une prise
            // en charge à 100 % le rendrait faux (ADR-047).
            'amount' => $balance > 0 ? $invoice->balance_amount : $invoice->total_amount,
            'amount_caption' => $balance > 0 ? 'Reste à payer' : ($invoice->status === InvoiceStatus::Covered ? 'Pris en charge' : 'Total réglé'),
            'amount_is_due' => $balance > 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function paymentStep(Payment $payment, Collection $names): array
    {
        $cancelled = $payment->status === PaymentStatus::Cancelled;
        $notes = array_values(array_filter([
            $payment->invoice->pharmacyDispense !== null ? 'Ticket Pharmacie' : null,
            $payment->method?->name,
            $payment->received_by !== null && $names->has($payment->received_by)
                ? 'Par '.$names->get($payment->received_by)
                : null,
        ]));

        return $this->step([
            'key' => 'payment-'.$payment->uuid,
            'type' => 'PAYMENT',
            'module' => null,
            'label' => 'Caisse',
            'qualifier' => $payment->payment_number,
            'state' => $cancelled ? 'CANCELLED' : 'DONE',
            'state_label' => $cancelled ? 'Paiement annulé' : 'Encaissé',
            'at' => $payment->paid_at ?? $payment->created_at,
            'notes' => $notes,
            'amount' => $payment->amount,
            'amount_caption' => $cancelled ? 'Annulé' : 'Encaissé',
        ]);
    }

    /**
     * La sortie prononcée par la Réception (CDC §33.3), ou — tant qu'elle
     * n'est pas prononcée alors que le parcours clinique est fini — la
     * mention que c'est elle qui est attendue. C'est la réponse à « pourquoi
     * ce passage est-il encore ouvert ? » lorsque plus aucun service n'a le
     * patient (ADR-090).
     *
     * @return array<string, mixed>|null
     */
    private function exitStep(Episode $episode, bool $canSeeBilling, Collection $names): ?array
    {
        if ($episode->administrative_exit_type !== null) {
            $type = $episode->administrative_exit_type;
            $notes = [];

            if ($episode->administrative_exit_by !== null && $names->has($episode->administrative_exit_by)) {
                $notes[] = 'Prononcée par '.$names->get($episode->administrative_exit_by);
            }

            $owes = $type->createsDebt() && $canSeeBilling;

            return $this->step([
                'key' => 'exit',
                'type' => 'EXIT',
                'module' => CatalogModule::Reception->value,
                'label' => 'Sortie',
                'state' => $type === AdministrativeExitType::PaidCash ? 'DONE' : 'WARNING',
                'state_label' => $type->label(),
                'at' => $episode->administrative_exit_at ?? $episode->ended_at,
                'notes' => $notes,
                'amount' => $owes ? $episode->administrative_exit_balance : null,
                'amount_caption' => $owes ? 'Reste dû à la sortie' : null,
                'amount_is_due' => $owes,
            ]);
        }

        if ($episode->status === EpisodeStatus::Open
            && $episode->administrative_status === EpisodeAdministrativeStatus::PendingSettlement) {
            return $this->step([
                'key' => 'exit',
                'type' => 'EXIT',
                'module' => CatalogModule::Reception->value,
                'label' => 'Sortie',
                'state' => 'PENDING',
                'state_label' => 'À prononcer par la Réception',
                'at' => null,
                'notes' => ['Le parcours clinique est terminé : reste le règlement et la sortie administrative.'],
            ]);
        }

        return null;
    }

    /**
     * Toutes les clés existent sur toutes les étapes, `null` quand elles ne
     * s'appliquent pas : un écran n'a pas à tester leur présence, et une clé
     * absente ne se confond pas avec une valeur vide.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function step(array $step): array
    {
        return [
            'key' => null,
            'type' => null,
            'module' => null,
            'label' => null,
            'from_label' => null,
            'qualifier' => null,
            'sequence' => null,
            'sequence_total' => null,
            'state' => 'DONE',
            'state_label' => null,
            'at' => null,
            'accepted_at' => null,
            'completed_at' => null,
            'notes' => [],
            'follow_up' => null,
            'items' => [],
            'amount' => null,
            'amount_caption' => null,
            'amount_is_due' => false,
            ...$step,
        ];
    }

    /**
     * Chronologique, l'étape sans date en dernier : c'est celle qui n'a pas
     * encore eu lieu. À horodatage égal, le sens du patient décide (la
     * Réception avant le service, le service avant la Caisse).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function chronological(array $steps): array
    {
        $indexed = [];

        foreach ($steps as $position => $step) {
            $indexed[] = [$step, $position];
        }

        usort($indexed, function (array $a, array $b) {
            $left = $a[0]['at'] instanceof CarbonInterface ? $a[0]['at']->getTimestamp() : PHP_INT_MAX;
            $right = $b[0]['at'] instanceof CarbonInterface ? $b[0]['at']->getTimestamp() : PHP_INT_MAX;

            return [$left, self::RANK[$a[0]['type']], $a[1]] <=> [$right, self::RANK[$b[0]['type']], $b[1]];
        });

        return array_map(fn (array $entry) => $entry[0], $indexed);
    }

    /**
     * Un seul appel pour tous les noms : les étapes n'exposent que des noms,
     * jamais des relations chargées qui finiraient sérialisées avec le
     * passage.
     *
     * @return Collection<int, string>
     */
    private function userNames(Collection $episodes, Collection $careOrders, Collection $dispenses, Collection $payments, Collection $prescriptions): Collection
    {
        $ids = collect()
            ->merge($episodes->pluck('created_by'))
            ->merge($episodes->pluck('administrative_exit_by'))
            ->merge($careOrders->flatten(1)->pluck('requested_by'))
            ->merge($dispenses->flatten(1)->pluck('requested_by'))
            ->merge($payments->flatten(1)->pluck('received_by'))
            ->merge($prescriptions->flatten(1)->pluck('prescribed_by'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function ordinal(int $number): string
    {
        return $number === 1 ? '1re' : $number.'e';
    }

    private function quantity(mixed $quantity): int
    {
        return (int) round((float) $quantity);
    }
}
