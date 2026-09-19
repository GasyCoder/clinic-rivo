<?php

namespace Tests\Feature\Episode;

use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\CareRecord;
use App\Models\CashSession;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PharmacyDispense;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\User;
use App\Support\EpisodePathwayTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-117 — le parcours d'un passage, composé une seule fois pour le dossier
 * du patient et pour le détail du passage.
 */
class EpisodePathwayTimelineTest extends TestCase
{
    use RefreshDatabase;

    private ?User $author = null;

    /**
     * Le défaut signalé : « Soins », « Soins » se lisait comme un doublon
     * alors que ce sont deux demandes du médecin, chacune avec sa suite.
     */
    public function test_two_care_requests_are_two_numbered_steps_each_with_its_own_follow_up(): void
    {
        [$episode] = $this->episodeWithTwoCareRequests();
        $viewer = $this->userWithPermissions(['patients.view', 'care_orders.view']);

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $viewer);

        $this->assertSame(
            ['RECEPTION', 'MEDICINE', 'CARE', 'CARE'],
            array_map(fn ($step) => $step['type'] === 'RECEPTION' ? 'RECEPTION' : $step['module'], $steps),
        );

        [, $medicine, $firstCare, $secondCare] = $steps;

        // Un service visité une seule fois garde son nom tout court.
        $this->assertNull($medicine['sequence']);
        $this->assertNull($medicine['qualifier']);

        $this->assertSame([1, 2], [$firstCare['sequence'], $secondCare['sequence']]);
        $this->assertSame([2, 2], [$firstCare['sequence_total'], $secondCare['sequence_total']]);
        $this->assertSame(['1re demande', '2e demande'], [$firstCare['qualifier'], $secondCare['qualifier']]);

        // « Médecine → Soins » : d'où vient la demande, et qui l'a faite.
        $this->assertSame('Médecine', $firstCare['from_label']);
        $this->assertContains('Demandé par Dr Rakoto', $firstCare['notes']);

        // La suite décidée par le médecin, différente pour chacune des deux.
        $this->assertSame('RETURN_TO_MEDICINE', $firstCare['follow_up']['code']);
        $this->assertSame('Retour en Médecine prévu après les soins', $firstCare['follow_up']['label']);
        $this->assertSame('DIRECT_EXIT', $secondCare['follow_up']['code']);
        $this->assertSame('Sortie directe après les soins (sans retour en Médecine)', $secondCare['follow_up']['label']);

        // Les actes de chaque demande, lus sur ce que les Soins ont réellement enregistré.
        $this->assertSame(
            [['name' => 'Injection IM', 'quantity' => '1.00', 'state' => 'DONE', 'state_label' => 'Réalisé']],
            $firstCare['items'],
        );
        $this->assertSame(
            [['name' => 'Pansement', 'quantity' => '2.00', 'state' => 'PENDING', 'state_label' => 'À réaliser']],
            $secondCare['items'],
        );

        // Une orientation qui n'a pas de demande de soins derrière elle n'invente pas de suite.
        $this->assertNull($medicine['follow_up']);
        $this->assertSame([], $medicine['items']);
    }

    public function test_the_follow_up_is_routing_information_but_the_requested_acts_need_care_orders_view(): void
    {
        [$episode] = $this->episodeWithTwoCareRequests();
        $viewer = $this->userWithPermissions(['patients.view']);

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $viewer);
        $care = array_values(array_filter($steps, fn ($step) => $step['module'] === 'CARE'));

        $this->assertSame('RETURN_TO_MEDICINE', $care[0]['follow_up']['code']);
        $this->assertSame('DIRECT_EXIT', $care[1]['follow_up']['code']);
        $this->assertSame([], $care[0]['items']);
        $this->assertSame([], $care[1]['items']);
    }

    /**
     * `CreateEpisodeOrientationAction` réutilise l'orientation Soins encore
     * active : deux demandes faites avant que les Soins aient terminé
     * partagent la même orientation. Ni l'une ni l'autre ne doit disparaître.
     */
    public function test_two_requests_sharing_one_soins_orientation_lose_neither(): void
    {
        $episode = $this->episode();
        $doctor = $this->author();
        $medicine = $this->orientation($episode, 'RECEPTION', 'MEDICINE', 'IN_PROGRESS', '2026-09-18 07:43', activeKey: "{$episode->id}:MEDICINE");
        $consultation = Consultation::create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'reason' => 'Douleur',
            'consulted_at' => CarbonImmutable::parse('2026-09-18 07:44'),
        ]);
        $care = $this->orientation($episode, 'MEDICINE', 'CARE', 'IN_PROGRESS', '2026-09-18 12:00', activeKey: "{$episode->id}:CARE");
        $direct = $this->careOrder($episode, $consultation, $medicine, $care, $doctor, returnToMedicine: false, at: '2026-09-18 12:00');
        $back = $this->careOrder($episode, $consultation, $medicine, $care, $doctor, returnToMedicine: true, at: '2026-09-18 12:10');
        $this->careOrderItem($direct, $this->catalogItem('INJECTION-IM', 'Injection IM'), 'Injection IM', '1.00');
        $this->careOrderItem($back, $this->catalogItem('PANSEMENT-S', 'Pansement'), 'Pansement', '2.00');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'care_orders.view']));
        $careSteps = array_values(array_filter($steps, fn ($step) => $step['module'] === 'CARE'));

        // Une seule orientation, donc une seule étape — pas de numéro à inventer.
        $this->assertCount(1, $careSteps);
        $this->assertNull($careSteps[0]['sequence']);

        // Les actes des deux demandes, dans l'ordre où ils ont été demandés.
        $this->assertSame(['Injection IM', 'Pansement'], array_column($careSteps[0]['items'], 'name'));

        // Un même médecin n'est nommé qu'une fois, et si l'une des demandes
        // attend le patient en Médecine, l'étape l'annonce.
        $this->assertSame(['Demandé par Dr Rakoto'], $careSteps[0]['notes']);
        $this->assertSame('RETURN_TO_MEDICINE', $careSteps[0]['follow_up']['code']);
    }

    public function test_a_service_reached_from_reception_shows_that_origin_and_a_single_visit_is_not_numbered(): void
    {
        $episode = $this->episode();
        $this->orientation($episode, 'RECEPTION', 'CARE', 'COMPLETED', '2026-09-18 08:00');

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $this->userWithPermissions(['patients.view']));

        $care = $steps[1];
        $this->assertSame('Réception', $care['from_label']);
        $this->assertNull($care['sequence']);
        $this->assertNull($care['follow_up']);
        $this->assertSame('DONE', $care['state']);
    }

    public function test_the_reception_step_names_who_registered_the_arrival_and_what_the_patient_came_for(): void
    {
        $episode = $this->episode(['priority' => 'EMERGENCY']);
        $this->serviceRequest($episode, 'Échographie obstétricale', 1);
        $this->serviceRequest($episode, 'Injection IM', 2);

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $this->userWithPermissions(['patients.view']));

        $this->assertSame('RECEPTION', $steps[0]['type']);
        $this->assertSame('Arrivée enregistrée', $steps[0]['state_label']);
        $this->assertSame(
            ['Enregistré par Dr Rakoto', 'Besoin : Échographie obstétricale, Injection IM ×2', 'Passage classé urgence'],
            $steps[0]['notes'],
        );
    }

    public function test_the_pharmacy_step_needs_pharmacy_view(): void
    {
        $episode = $this->episode();
        PharmacyDispense::query()->create([
            'type' => 'INTERNAL',
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'status' => 'AWAITING_PAYMENT',
            'requested_at' => CarbonImmutable::parse('2026-09-18 10:00'),
            'requested_by' => $this->author()->id,
        ]);
        $timeline = app(EpisodePathwayTimeline::class);

        $without = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view']));
        $with = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'pharmacy.view'], 'PHARMACIST'));

        $this->assertNotContains('PHARMACY', array_column($without, 'type'));

        $pharmacy = array_values(array_filter($with, fn ($step) => $step['type'] === 'PHARMACY'))[0];
        $this->assertSame('Pharmacie', $pharmacy['label']);
        $this->assertSame('Ordonnance', $pharmacy['qualifier']);
        $this->assertSame('PENDING', $pharmacy['state']);
        $this->assertSame('En attente de règlement', $pharmacy['state_label']);
    }

    /**
     * Le défaut signalé sur A-26-0001-01 : l'ordonnance était bien partie à la
     * Pharmacie (dispensation « délivrée »), mais le médecin qui l'a prescrite
     * — sans `pharmacy.view` — ne la voyait nulle part dans le parcours.
     */
    public function test_the_prescriber_sees_the_pharmacy_step_of_his_prescription_without_pharmacy_view(): void
    {
        $episode = $this->episode();
        $prescription = $this->prescription($episode, lines: 4);
        $this->dispenseFor($prescription, $episode, 'DISPENSED', completedAt: '2026-09-18 11:00');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view'], 'PRESCRIBER'));

        $pharmacy = array_values(array_filter($steps, fn ($step) => $step['type'] === 'PHARMACY'));
        $this->assertCount(1, $pharmacy, 'l’ordonnance et sa dispensation ne font qu’une étape');
        $this->assertSame('Pharmacie', $pharmacy[0]['label']);
        $this->assertSame('Médecine', $pharmacy[0]['from_label']);
        $this->assertSame('Ordonnance', $pharmacy[0]['qualifier']);
        $this->assertSame('DONE', $pharmacy[0]['state']);
        $this->assertSame('Délivrée', $pharmacy[0]['state_label']);
        $this->assertSame(['Prescrite par Dr Rakoto', '4 médicaments'], $pharmacy[0]['notes']);
        $this->assertNotNull($pharmacy[0]['completed_at']);
    }

    public function test_the_prescriber_reads_the_outcome_but_never_the_payment_state_of_the_pharmacy(): void
    {
        $episode = $this->episode();
        $prescription = $this->prescription($episode);
        $this->dispenseFor($prescription, $episode, 'AWAITING_PAYMENT');
        $timeline = app(EpisodePathwayTimeline::class);

        $prescriber = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view'], 'PRESCRIBER'));
        $pharmacist = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view', 'pharmacy.view'], 'PHARMACIST'));

        $prescriberStep = array_values(array_filter($prescriber, fn ($step) => $step['type'] === 'PHARMACY'))[0];
        $pharmacistStep = array_values(array_filter($pharmacist, fn ($step) => $step['type'] === 'PHARMACY'))[0];

        // « En attente de règlement » est un état de la Pharmacie et de la Caisse.
        $this->assertSame('Transmise à la Pharmacie', $prescriberStep['state_label']);
        $this->assertSame('ACTIVE', $prescriberStep['state']);
        $this->assertSame('En attente de règlement', $pharmacistStep['state_label']);
        $this->assertSame('PENDING', $pharmacistStep['state']);
    }

    public function test_a_prescription_made_only_of_manual_lines_never_reached_the_pharmacy_and_says_so(): void
    {
        $episode = $this->episode();
        $this->prescription($episode, lines: 1);

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view'], 'PRESCRIBER'));

        $step = array_values(array_filter($steps, fn ($step) => $step['type'] === 'PHARMACY'))[0];

        // Aucune dispensation n'existe : annoncer « Pharmacie » serait inventer un passage.
        $this->assertSame('Ordonnance', $step['label']);
        $this->assertNull($step['from_label']);
        $this->assertSame('Établie', $step['state_label']);
        $this->assertContains('Hors référentiel : aucune demande de dispensation à la Pharmacie.', $step['notes']);
        $this->assertContains('1 médicament', $step['notes']);
    }

    public function test_a_cancelled_prescription_is_shown_as_cancelled(): void
    {
        $episode = $this->episode();
        $prescription = $this->prescription($episode, status: 'CANCELLED');
        $this->dispenseFor($prescription, $episode, 'CANCELLED');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view'], 'PRESCRIBER'));

        $step = array_values(array_filter($steps, fn ($step) => $step['type'] === 'PHARMACY'))[0];
        $this->assertSame('CANCELLED', $step['state']);
        $this->assertSame('Ordonnance annulée', $step['state_label']);
    }

    public function test_prescriptions_view_alone_does_not_expose_other_pharmacy_dispenses(): void
    {
        $episode = $this->episode();
        PharmacyDispense::query()->create([
            'type' => 'EXTERNAL',
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'status' => 'AWAITING_PAYMENT',
            'requested_at' => CarbonImmutable::parse('2026-09-18 10:00'),
        ]);
        $timeline = app(EpisodePathwayTimeline::class);

        $prescriber = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'prescriptions.view'], 'PRESCRIBER'));
        $pharmacist = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'pharmacy.view'], 'PHARMACIST'));

        // Un achat au comptoir n'est l'ordonnance de personne : il appartient à la Pharmacie.
        $this->assertNotContains('PHARMACY', array_column($prescriber, 'type'));
        $this->assertContains('PHARMACY', array_column($pharmacist, 'type'));
    }

    public function test_a_pharmacist_without_prescriptions_view_still_sees_the_dispense_of_a_prescription(): void
    {
        $episode = $this->episode();
        $prescription = $this->prescription($episode);
        $this->dispenseFor($prescription, $episode, 'READY');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'pharmacy.view'], 'PHARMACIST'));

        $pharmacy = array_values(array_filter($steps, fn ($step) => $step['type'] === 'PHARMACY'));
        $this->assertCount(1, $pharmacy);
        $this->assertSame('Médecine', $pharmacy[0]['from_label']);
        $this->assertSame('Payée · prête à délivrer', $pharmacy[0]['state_label']);
        // Sans `prescriptions.view`, le nombre de médicaments n'est pas servi.
        $this->assertNotContains('1 médicament', $pharmacy[0]['notes']);
    }

    public function test_invoices_and_payments_are_gated_by_billing_view_and_payments_view(): void
    {
        $episode = $this->episode();
        $invoice = $this->invoice($episode, total: '50000.00', paid: '20000.00', balance: '30000.00', status: 'PARTIALLY_PAID');
        $this->payment($invoice, '20000.00');
        $timeline = app(EpisodePathwayTimeline::class);

        $none = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view']));
        $billingOnly = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view'], 'BILLER'));
        $both = $timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view', 'payments.view'], 'CASHIER'));

        $this->assertSame(['RECEPTION'], array_column($none, 'type'));
        $this->assertSame(['RECEPTION', 'INVOICE'], array_column($billingOnly, 'type'));
        $this->assertSame(['RECEPTION', 'INVOICE', 'PAYMENT'], array_column($both, 'type'));

        $invoiceStep = $both[1];
        $this->assertSame('Paiement partiel', $invoiceStep['state_label']);
        // Le reste dû, lu sur la facture — jamais recalculé (ADR-047).
        $this->assertSame('30000.00', $invoiceStep['amount']);
        $this->assertSame('Reste à payer', $invoiceStep['amount_caption']);
        $this->assertTrue($invoiceStep['amount_is_due']);

        $paymentStep = $both[2];
        $this->assertSame('Caisse', $paymentStep['label']);
        $this->assertSame('Encaissé', $paymentStep['state_label']);
        $this->assertSame('20000.00', $paymentStep['amount']);
        $this->assertSame(['Espèces', 'Par Dr Rakoto'], $paymentStep['notes']);
    }

    /**
     * La Caisse encaisse le ticket de la Pharmacie sans en voir la Pharmacie :
     * sans mention, une facture réglée après la sortie ne se rattache à rien.
     */
    public function test_a_pharmacy_ticket_and_its_payment_say_where_they_come_from(): void
    {
        $episode = $this->episode();
        $consultationInvoice = $this->invoice($episode, total: '20000.00', paid: '20000.00', balance: '0.00', status: 'PAID');
        $ticket = $this->invoice($episode, total: '4800.00', paid: '4800.00', balance: '0.00', status: 'PAID', number: 'AF-000006');
        PharmacyDispense::query()->create([
            'type' => 'INTERNAL',
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_id' => $ticket->id,
            'status' => 'DISPENSED',
            'requested_at' => CarbonImmutable::parse('2026-09-18 09:00'),
        ]);
        $this->payment($consultationInvoice, '20000.00');
        $this->payment($ticket, '4800.00', number: 'P-26-0002');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view', 'payments.view'], 'CASHIER'));

        $invoices = array_values(array_filter($steps, fn ($step) => $step['type'] === 'INVOICE'));
        $payments = array_values(array_filter($steps, fn ($step) => $step['type'] === 'PAYMENT'));

        $this->assertSame([[], ['Ticket Pharmacie']], array_column($invoices, 'notes'));
        $this->assertNotContains('Ticket Pharmacie', $payments[0]['notes']);
        $this->assertSame('Ticket Pharmacie', $payments[1]['notes'][0]);

        // Le ticket n'ouvre pas pour autant l'étape de la Pharmacie à qui n'en a pas le droit.
        $this->assertNotContains('PHARMACY', array_column($steps, 'type'));
    }

    public function test_a_settled_invoice_reports_the_total_and_a_cancelled_one_is_left_out(): void
    {
        $episode = $this->episode();
        $this->invoice($episode, total: '50000.00', paid: '50000.00', balance: '0.00', status: 'PAID');
        $this->invoice($episode, total: '9000.00', paid: '0.00', balance: '0.00', status: 'CANCELLED', number: 'AF-000002');

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view']));

        $invoices = array_values(array_filter($steps, fn ($step) => $step['type'] === 'INVOICE'));
        $this->assertCount(1, $invoices);
        $this->assertSame('DONE', $invoices[0]['state']);
        $this->assertSame('Total réglé', $invoices[0]['amount_caption']);
        $this->assertFalse($invoices[0]['amount_is_due']);
    }

    public function test_a_passage_waiting_for_settlement_ends_with_the_exit_still_to_pronounce(): void
    {
        $episode = $this->episode(['administrative_status' => 'PENDING_SETTLEMENT']);
        $this->orientation($episode, 'RECEPTION', 'MEDICINE', 'COMPLETED', '2026-09-18 08:00');

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $this->userWithPermissions(['patients.view']));

        $exit = end($steps);
        $this->assertSame('EXIT', $exit['type']);
        $this->assertSame('PENDING', $exit['state']);
        $this->assertSame('À prononcer par la Réception', $exit['state_label']);
        // Une étape qui n'a pas eu lieu n'invente pas de date.
        $this->assertNull($exit['at']);
    }

    public function test_a_pronounced_exit_shows_its_type_and_the_debt_only_to_billing(): void
    {
        $episode = $this->episode([
            'status' => 'CLOSED',
            'administrative_status' => 'DISCHARGED_DEBT',
            'administrative_exit_type' => 'DEBT_VALIDATED',
            'administrative_exit_at' => CarbonImmutable::parse('2026-09-19 18:00'),
            'administrative_exit_by' => $this->author()->id,
            'administrative_exit_balance' => '30000.00',
            'administrative_exit_reason' => 'Famille garante',
        ]);
        $timeline = app(EpisodePathwayTimeline::class);

        $last = fn (array $steps) => $steps[array_key_last($steps)];
        $plain = $last($timeline->forEpisode($episode, $this->userWithPermissions(['patients.view'])));
        $billing = $last($timeline->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view'], 'BILLER')));

        foreach ([$plain, $billing] as $exit) {
            $this->assertSame('EXIT', $exit['type']);
            $this->assertSame('WARNING', $exit['state']);
            $this->assertSame('Sorti — dette validée', $exit['state_label']);
            $this->assertSame(['Prononcée par Dr Rakoto'], $exit['notes']);
        }

        $this->assertNull($plain['amount']);
        $this->assertSame('30000.00', $billing['amount']);
        $this->assertSame('Reste dû à la sortie', $billing['amount_caption']);
        $this->assertTrue($billing['amount_is_due']);
    }

    public function test_a_cash_exit_is_settled_and_carries_no_debt(): void
    {
        $episode = $this->episode([
            'status' => 'CLOSED',
            'administrative_status' => 'DISCHARGED_PAID',
            'administrative_exit_type' => 'PAID_CASH',
            'administrative_exit_at' => CarbonImmutable::parse('2026-09-19 18:00'),
            'administrative_exit_by' => $this->author()->id,
            'administrative_exit_balance' => '0.00',
        ]);

        $steps = app(EpisodePathwayTimeline::class)
            ->forEpisode($episode, $this->userWithPermissions(['patients.view', 'billing.view']));
        $exit = end($steps);

        $this->assertSame('DONE', $exit['state']);
        $this->assertNull($exit['amount']);
        $this->assertFalse($exit['amount_is_due']);
    }

    public function test_steps_are_chronological_whatever_their_source(): void
    {
        $episode = $this->episode(['started_at' => CarbonImmutable::parse('2026-09-18 07:00')]);
        $this->orientation($episode, 'RECEPTION', 'MEDICINE', 'COMPLETED', '2026-09-18 07:05');
        $invoice = $this->invoice($episode, total: '50000.00', paid: '50000.00', balance: '0.00', status: 'PAID', at: '2026-09-18 09:00');
        $this->payment($invoice, '50000.00', '2026-09-18 09:30');
        PharmacyDispense::query()->create([
            'type' => 'INTERNAL',
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'status' => 'DISPENSED',
            'requested_at' => CarbonImmutable::parse('2026-09-18 10:00'),
            'completed_at' => CarbonImmutable::parse('2026-09-18 10:20'),
        ]);
        $this->orientation($episode, 'MEDICINE', 'CARE', 'COMPLETED', '2026-09-18 08:00');

        $steps = app(EpisodePathwayTimeline::class)->forEpisode($episode, $this->userWithPermissions(
            ['patients.view', 'billing.view', 'payments.view', 'pharmacy.view'],
            'EVERYTHING',
        ));

        $this->assertSame(
            ['RECEPTION', 'MEDICINE', 'CARE', 'INVOICE', 'PAYMENT', 'PHARMACY'],
            array_map(fn ($step) => $step['type'] === 'ORIENTATION' ? $step['module'] : $step['type'], $steps),
        );
    }

    public function test_many_episodes_are_composed_in_a_constant_number_of_queries(): void
    {
        $patient = $this->patient();
        $episodes = collect(range(1, 4))->map(function (int $n) use ($patient) {
            $episode = $this->episode(['episode_number' => "M-26-0001-0{$n}"], $patient);
            $this->orientation($episode, 'RECEPTION', 'CARE', 'COMPLETED', '2026-09-18 08:00');
            $this->dispenseFor($this->prescription($episode, lines: 2), $episode, 'DISPENSED');

            return $episode;
        });
        // Toutes les sources lues : le nombre de requêtes ne doit dépendre d'aucune.
        $viewer = $this->userWithPermissions(
            ['patients.view', 'care_orders.view', 'pharmacy.view', 'prescriptions.view', 'billing.view', 'payments.view'],
            'EVERYTHING',
        );
        $timeline = app(EpisodePathwayTimeline::class);
        $timeline->forEpisodes($episodes->take(1), $viewer);

        $count = function (int $episodesCount) use ($timeline, $episodes, $viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $timeline->forEpisodes($episodes->take($episodesCount), $viewer);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Un passage ou quatre : le même nombre de requêtes — le temps de
        // l'écran ne dépend pas de l'historique du patient.
        $this->assertSame($count(1), $count(4));
        $this->assertCount(4, $timeline->forEpisodes($episodes, $viewer));
    }

    public function test_the_patient_file_serves_the_pathway_of_every_episode(): void
    {
        $patient = $this->patient();
        $episode = $this->episode([], $patient);
        $this->orientation($episode, 'RECEPTION', 'CARE', 'IN_PROGRESS', '2026-09-18 08:00', activeKey: 'A:CARE');
        $viewer = $this->userWithPermissions(['patients.view']);

        $this->actingAs($viewer)->get("/patients/{$patient->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Show')
                ->has('patient.episodes.0.pathway', 2)
                ->where('patient.episodes.0.pathway.0.type', 'RECEPTION')
                ->where('patient.episodes.0.pathway.1.module', 'CARE')
                ->where('patient.episodes.0.pathway.1.state', 'ACTIVE')
            );
    }

    /**
     * @return array{0: Episode, 1: EpisodeOrientation, 2: EpisodeOrientation}
     */
    private function episodeWithTwoCareRequests(): array
    {
        $episode = $this->episode(['started_at' => CarbonImmutable::parse('2026-09-18 07:43')]);
        $doctor = User::factory()->create([
            'name' => 'Dr Rakoto',
            'role_id' => Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine'])->id,
        ]);
        $medicine = $this->orientation($episode, 'RECEPTION', 'MEDICINE', 'IN_PROGRESS', '2026-09-18 07:43', activeKey: "{$episode->id}:MEDICINE");
        $consultation = Consultation::create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'reason' => 'Échographie obstétricale',
            'consulted_at' => CarbonImmutable::parse('2026-09-18 07:44'),
        ]);
        $injection = $this->catalogItem('INJECTION-IM', 'Injection IM');
        $dressing = $this->catalogItem('PANSEMENT-S', 'Pansement');

        $firstCare = $this->orientation($episode, 'MEDICINE', 'CARE', 'COMPLETED', '2026-09-18 12:57');
        $firstOrder = $this->careOrder($episode, $consultation, $medicine, $firstCare, $doctor, returnToMedicine: true, at: '2026-09-18 12:57');
        $firstItem = $this->careOrderItem($firstOrder, $injection, 'Injection IM', '1.00');
        $careRecord = CareRecord::create(['episode_id' => $episode->id, 'created_by' => $doctor->id, 'updated_by' => $doctor->id]);
        $careRecord->procedures()->create([
            'catalog_item_id' => $injection->id,
            'catalog_item_uuid' => $injection->uuid,
            'care_order_item_id' => $firstItem->id,
            'procedure_code' => 'INJECTION-IM',
            'procedure_name' => 'Injection IM',
            'quantity' => 1,
            'performed_by' => $doctor->id,
            'performed_at' => CarbonImmutable::parse('2026-09-18 15:50'),
        ]);

        $secondCare = $this->orientation($episode, 'MEDICINE', 'CARE', 'IN_PROGRESS', '2026-09-18 16:28', activeKey: "{$episode->id}:CARE");
        $secondOrder = $this->careOrder($episode, $consultation, $medicine, $secondCare, $doctor, returnToMedicine: false, at: '2026-09-18 16:28');
        $this->careOrderItem($secondOrder, $dressing, 'Pansement', '2.00');

        return [$episode->fresh(), $firstCare, $secondCare];
    }

    private function episode(array $attributes = [], ?Patient $patient = null): Episode
    {
        $patient ??= $this->patient();

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => CarbonImmutable::parse('2026-09-18 07:00'),
            'created_by' => $this->author()->id,
            ...$attributes,
        ]);
    }

    private function patient(): Patient
    {
        return Patient::create([
            'patient_number' => 'M-26-0001',
            'first_name' => 'Malala',
            'last_name' => 'Rasoamifidy',
            'birth_date' => '1980-05-12',
            'sex' => 'F',
        ]);
    }

    private function orientation(Episode $episode, string $from, string $to, string $status, string $at, ?string $activeKey = null): EpisodeOrientation
    {
        $orientedAt = CarbonImmutable::parse($at);

        return EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => $from,
            'destination_module' => $to,
            'status' => $status,
            'active_key' => $activeKey,
            'oriented_by' => $this->author()->id,
            'oriented_at' => $orientedAt,
            'accepted_at' => $status === 'PENDING' ? null : $orientedAt->addMinutes(5),
            'completed_at' => $status === 'COMPLETED' ? $orientedAt->addMinutes(30) : null,
        ]);
    }

    private function careOrder(Episode $episode, Consultation $consultation, EpisodeOrientation $source, EpisodeOrientation $care, User $doctor, bool $returnToMedicine, string $at): CareOrder
    {
        return CareOrder::create([
            'episode_id' => $episode->id,
            'consultation_id' => $consultation->id,
            'source_orientation_id' => $source->id,
            'care_orientation_id' => $care->id,
            'requested_by' => $doctor->id,
            'requires_return_to_medicine' => $returnToMedicine,
            'status' => 'PENDING',
            'ordered_at' => CarbonImmutable::parse($at),
        ]);
    }

    private function careOrderItem(CareOrder $order, CatalogItem $item, string $name, string $quantity): CareOrderItem
    {
        return CareOrderItem::create([
            'care_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'catalog_item_code_snapshot' => $item->code,
            'catalog_item_name_snapshot' => $name,
            'quantity' => $quantity,
        ]);
    }

    private function catalogItem(string $code, string $name): CatalogItem
    {
        return CatalogItem::create([
            'code' => $code,
            'name' => $name,
            'type' => 'SERVICE',
            'module' => 'CARE',
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'clinician_orderable' => true,
            'created_by' => $this->author()->id,
            'updated_by' => $this->author()->id,
        ]);
    }

    private function serviceRequest(Episode $episode, string $designation, int $quantity): EpisodeServiceRequest
    {
        $item = $this->catalogItem(strtoupper(substr(md5($designation), 0, 8)), $designation);

        return EpisodeServiceRequest::query()->create([
            'episode_id' => $episode->id,
            'catalog_item_id' => $item->id,
            'catalog_item_uuid' => $item->uuid,
            'catalog_code' => $item->code,
            'designation' => $designation,
            'module' => 'IMAGING',
            'routing_mode' => 'MEDICINE_DIRECT',
            'unit' => 'acte',
            'unit_price' => '20000.00',
            'currency' => 'MGA',
            'quantity' => $quantity,
            'created_by' => $this->author()->id,
        ]);
    }

    private function prescription(Episode $episode, int $lines = 2, string $status = 'ACTIVE'): Prescription
    {
        $doctor = $this->author();
        $consultation = Consultation::query()->where('episode_id', $episode->id)->first()
            ?? Consultation::create([
                'episode_id' => $episode->id,
                'doctor_id' => $doctor->id,
                'reason' => 'Douleur',
                'consulted_at' => CarbonImmutable::parse('2026-09-18 07:44'),
            ]);
        $prescription = Prescription::create([
            'consultation_id' => $consultation->id,
            'status' => $status,
            'prescribed_by' => $doctor->id,
            'prescribed_at' => CarbonImmutable::parse('2026-09-18 09:00'),
        ]);

        foreach (range(1, $lines) as $n) {
            PrescriptionLine::create([
                'prescription_id' => $prescription->id,
                'medication_name' => "Médicament {$n}",
                'dosage' => '500 mg',
                'frequency' => '3 fois/jour',
                'duration' => '4 jours',
                'is_manual_entry' => true,
            ]);
        }

        return $prescription;
    }

    private function dispenseFor(Prescription $prescription, Episode $episode, string $status, ?string $completedAt = null): PharmacyDispense
    {
        return PharmacyDispense::query()->create([
            'type' => 'INTERNAL',
            'prescription_id' => $prescription->id,
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'status' => $status,
            'requested_at' => CarbonImmutable::parse('2026-09-18 09:00'),
            'requested_by' => $this->author()->id,
            'completed_at' => $completedAt ? CarbonImmutable::parse($completedAt) : null,
        ]);
    }

    private function invoice(Episode $episode, string $total, string $paid, string $balance, string $status, string $number = 'AF-000001', ?string $at = null): Invoice
    {
        return Invoice::create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_number' => $number,
            'status' => $status,
            'currency' => 'MGA',
            'financial_mode' => 'SELF',
            'subtotal_amount' => $total,
            'discount_amount' => '0.00',
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'validated_at' => $at ? CarbonImmutable::parse($at) : CarbonImmutable::parse('2026-09-18 08:30'),
            'created_by' => $this->author()->id,
        ]);
    }

    private function payment(Invoice $invoice, string $amount, string $paidAt = '2026-09-18 09:00', string $number = 'P-26-0001'): Payment
    {
        $method = PaymentMethod::query()->firstOrCreate(
            ['code' => 'CASH'],
            ['name' => 'Espèces', 'category' => 'CASH', 'active' => true],
        );
        $session = CashSession::query()->firstOrCreate(
            ['session_number' => 'C-26-0001'],
            [
                'active_key' => 'SINGLE_OPEN_CASH',
                'status' => 'OPEN',
                'opening_amount' => 0,
                'opened_by' => $this->author()->id,
                'opened_at' => CarbonImmutable::parse('2026-09-18 07:00'),
            ],
        );

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'payment_number' => $number,
            'amount' => $amount,
            'currency' => 'MGA',
            'status' => 'COMPLETED',
            'paid_at' => CarbonImmutable::parse($paidAt),
            'cash_session_id' => $session->id,
            'received_by' => $this->author()->id,
        ]);
    }

    private function author(): User
    {
        return $this->author ??= User::factory()->create([
            'name' => 'Dr Rakoto',
            'role_id' => Role::query()->firstOrCreate(['code' => 'AUTHOR'], ['name' => 'Auteur'])->id,
        ]);
    }

    private function userWithPermissions(array $permissions, string $roleCode = 'VIEWER'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
