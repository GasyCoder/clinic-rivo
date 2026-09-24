<?php

namespace Tests\Feature\Reception;

use App\Actions\Episode\MarkEpisodeEmergencyAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\MedicineForm;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\PharmacyDispense;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-104 — le panier d'arrivée porte deux rayons : les prestations, qui
 * ouvrent un parcours clinique et rejoignent la facture du passage, et les
 * médicaments, qui réservent du stock et partent sur un ticket Pharmacie
 * distinct réglé à la Caisse.
 */
class ReceptionPharmacyCartTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->receptionist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
        ]);
    }

    public function test_the_need_page_offers_the_pharmacy_aisle_with_its_price_and_stock(): void
    {
        $this->medicine();

        $this->actingAs($this->receptionist)->get('/reception/patients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('capabilities.can_sell_medicines', true)
                ->has('pharmacyCatalog', 1)
                ->where('pharmacyCatalog.0.kind', 'MEDICINE')
                ->where('pharmacyCatalog.0.unit_price', '500.00')
                ->where('pharmacyCatalog.0.available_quantity', 12)
                // La Réception lit la disponibilité, jamais le détail des
                // lots : sortir du stock n'est pas son geste (ADR-036).
                ->missing('pharmacyCatalog.0.lots'));
    }

    /**
     * L'estimation est la réponse à « je veux juste savoir le prix » : deux
     * sous-totaux, parce que ce sont deux documents, et rien de créé.
     */
    public function test_the_estimate_prices_both_aisles_and_creates_nothing(): void
    {
        $service = $this->service();
        $medicine = $this->medicine();

        $this->actingAs($this->receptionist)->postJson('/reception/estimates', [
            'lines' => [
                ['kind' => 'SERVICE', 'catalog_item_uuid' => $service->uuid, 'quantity' => 1],
                ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 4],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('services_total', '20000.00')
            ->assertJsonPath('medicines_total', '2000.00')
            ->assertJsonPath('total_amount', '22000.00');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('pharmacy_dispenses', 0);
        // Une estimation ne touche pas au stock : rien n'est réservé.
        $this->assertDatabaseCount('medicine_stock_reservations', 0);
    }

    public function test_a_medicine_quantity_must_be_a_whole_number(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->receptionist)->postJson('/reception/estimates', [
            'lines' => [
                ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => '1.5'],
            ],
        ])->assertStatus(422);
    }

    /**
     * Le cas du client : le patient ne vient que pour des médicaments. On
     * crée quand même son dossier et son passage, puis on bascule à la
     * Caisse — aucune file clinique n'a de raison de s'ouvrir.
     */
    public function test_a_medicines_only_passage_opens_no_clinical_queue_and_goes_to_the_cash_desk(): void
    {
        $medicine = $this->medicine();
        $episode = $this->arrive([
            ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 4],
        ]);

        $this->setSelfFunded($episode);

        $this->actingAs($this->receptionist)->post(
            route('reception.passages.services.store', $episode),
            [
                'catalog_lines' => [
                    ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 4],
                ],
                'payment_choice' => 'LATER',
            ],
        )->assertRedirectContains('/cash');

        $dispense = PharmacyDispense::query()->with('invoice')->sole();
        $episode->refresh();

        // Le ticket appartient au passage : il se retrouve à la Caisse par
        // son numéro comme par le numéro de passage (ADR-050).
        $this->assertSame($episode->id, $dispense->episode_id);
        $this->assertSame($episode->patient_id, $dispense->patient_id);
        $this->assertSame(PharmacyDispenseStatus::AwaitingPayment, $dispense->status);

        // Aucun parcours clinique : personne n'attend ce patient dans une
        // file, et le passage part directement en règlement (ADR-090).
        $this->assertSame(0, EpisodeOrientation::query()->where('episode_id', $episode->id)->count());
        $this->assertSame(
            EpisodeAdministrativeStatus::PendingSettlement,
            $episode->administrative_status,
        );
        $this->assertNotNull($episode->service_plan_finalized_at);

        // Le stock est réservé, jamais sorti : il ne bouge qu'après le
        // règlement encaissé par la Caisse (ADR-049).
        $this->assertSame(12, (int) MedicineLot::query()->sum('quantity_on_hand'));
        $this->assertSame(4, (int) $dispense->lines()->sole()->counterReservations()->sum('remaining_quantity'));
    }

    /**
     * Prestations et médicaments dans le même panier : deux documents, pas
     * un total unique. Une facture mixte partiellement payée empêcherait de
     * décider si la Pharmacie peut délivrer (ADR-049, ADR-050).
     */
    public function test_a_mixed_cart_bills_the_services_and_the_medicines_separately(): void
    {
        $service = $this->service();
        $medicine = $this->medicine();
        $lines = [
            ['kind' => 'SERVICE', 'catalog_item_uuid' => $service->uuid, 'quantity' => 1],
            ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2],
        ];

        $episode = $this->arrive($lines);
        $this->setSelfFunded($episode);

        $this->actingAs($this->receptionist)->post(
            route('reception.passages.services.store', $episode),
            ['catalog_lines' => $lines, 'payment_choice' => 'LATER'],
        )->assertRedirect();

        $episode->refresh();
        $dispense = PharmacyDispense::query()->with('invoice')->sole();
        $episodeInvoice = Invoice::query()
            ->where('episode_id', $episode->id)
            ->whereKeyNot($dispense->invoice_id)
            ->sole();

        // La facture du passage ne porte que la prestation.
        $this->assertSame('20000.00', $episodeInvoice->total_amount);
        $this->assertSame('1000.00', $dispense->invoice->total_amount);
        $this->assertNotSame($episodeInvoice->id, $dispense->invoice->id);

        // ADR-177 — la prestation garde le passage dans le circuit clinique :
        // il est visible des services, sans orientation déduite de la prestation.
        $this->assertSame(0, EpisodeOrientation::query()->where('episode_id', $episode->id)->count());
        $this->assertNotSame(
            EpisodeAdministrativeStatus::PendingSettlement,
            $episode->administrative_status,
        );
    }

    /**
     * Le brouillon doit conserver le rayon de chaque ligne : relu comme une
     * prestation, un médicament serait routé vers une file clinique.
     */
    public function test_a_resumed_draft_keeps_the_aisle_of_each_line(): void
    {
        $medicine = $this->medicine();
        $episode = $this->arrive([
            ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2],
        ]);

        $this->actingAs($this->receptionist)
            ->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receptionDraft.catalog_lines.0.kind', 'MEDICINE')
                ->where('receptionDraft.catalog_lines.0.catalog_item_uuid', $medicine->catalogItem->uuid));
    }

    /**
     * La couverture Mutuelle/Personnel porte sur les prestations du
     * passage, jamais sur le ticket Pharmacie réglé au tarif Sans mutuelle :
     * lui appliquer un taux inventerait une convention que personne n'a
     * validée (ADR-045, ADR-047).
     *
     * Une ligne médicament forgée est **refusée par son nom**, pas ignorée
     * en silence — même principe que l'ADR-093 : l'appelant doit savoir ce
     * que le serveur a écarté.
     */
    public function test_the_coverage_preview_refuses_a_forged_medicine_line(): void
    {
        $service = $this->service();
        $medicine = $this->medicine();
        $episode = $this->arrive([
            ['kind' => 'SERVICE', 'catalog_item_uuid' => $service->uuid, 'quantity' => 1],
            ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2],
        ]);

        $this->actingAs($this->receptionist)->postJson(
            route('reception.passages.financial-context.store', $episode),
            [
                'financial_mode' => EpisodeFinancialMode::Self->value,
                'lines' => [
                    ['kind' => 'SERVICE', 'catalog_item_uuid' => $service->uuid, 'quantity' => 1],
                    ['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2],
                ],
            ],
        )->assertJsonValidationErrors(['lines.1.catalog_item_uuid']);

        // La prestation seule est bien chiffrée : c'est ce que la Réception
        // envoie réellement depuis l'écran.
        $this->actingAs($this->receptionist)->postJson(
            route('reception.passages.financial-context.store', $episode),
            [
                'financial_mode' => EpisodeFinancialMode::Self->value,
                'lines' => [['kind' => 'SERVICE', 'catalog_item_uuid' => $service->uuid, 'quantity' => 1]],
            ],
        )
            ->assertOk()
            ->assertJsonPath('preview.totals.gross_amount', '20000.00');
    }

    /**
     * Le raccourci « Terminer — envoyer à la Caisse » de l'étape 5 : le
     * patient ne verra personne, et aucune couverture ne s'applique à son
     * ticket. Le passage se confirme donc **sans** mode financier choisi —
     * l'étape de prise en charge refuserait de calculer une couverture sur
     * zéro prestation.
     */
    public function test_a_medicines_only_passage_is_confirmed_without_choosing_a_coverage(): void
    {
        $medicine = $this->medicine();
        $lines = [['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 3]];
        $episode = $this->arrive($lines);

        // Aucun appel à setSelfFunded() : le mode est encore null, comme
        // lorsque la Réception utilise le raccourci.
        $this->assertNull($episode->financial_mode);

        $this->actingAs($this->receptionist)->post(
            route('reception.passages.services.store', $episode),
            ['catalog_lines' => $lines, 'payment_choice' => 'LATER'],
        )->assertRedirectContains('/cash');

        $episode->refresh();

        // Le ticket est bien parti à la Caisse.
        $dispense = PharmacyDispense::query()->with('invoice')->sole();
        $this->assertSame($episode->id, $dispense->episode_id);
        $this->assertSame('1500.00', $dispense->invoice->total_amount);

        // Le passage n'est pas laissé « à régulariser » : le patient
        // supporte le tarif Sans mutuelle de ses médicaments (ADR-104).
        $this->assertSame(EpisodeFinancialMode::Self, $episode->financial_mode);
        $this->assertNotNull($episode->financial_context_completed_at);
        $this->assertSame(
            EpisodeAdministrativeStatus::PendingSettlement,
            $episode->administrative_status,
        );
        $this->assertSame(0, EpisodeOrientation::query()->where('episode_id', $episode->id)->count());
    }

    /** Un mode déjà choisi par la Réception n'est jamais réécrit. */
    public function test_an_explicit_coverage_choice_survives_the_shortcut(): void
    {
        $medicine = $this->medicine();
        $lines = [['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1]];
        $episode = $this->arrive($lines);

        $episode->forceFill([
            'financial_mode' => EpisodeFinancialMode::Mutual,
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $this->receptionist->id,
        ])->save();

        $this->actingAs($this->receptionist)->post(
            route('reception.passages.services.store', $episode),
            ['catalog_lines' => $lines, 'payment_choice' => 'LATER'],
        )->assertRedirectContains('/cash');

        $this->assertSame(EpisodeFinancialMode::Mutual, $episode->fresh()->financial_mode);
    }

    /**
     * L'écran ne propose plus l'urgence sur un achat de médicaments, mais
     * un passage peut avoir été requalifié autrement (Médecine, ADR-056).
     * Le serveur ne doit alors **jamais** le déclarer en attente de
     * règlement : deux files cliniques sont ouvertes et attendent ce
     * patient — même garde que l'ADR-054.
     */
    public function test_a_passage_with_an_open_clinical_queue_is_never_settled_by_the_shortcut(): void
    {
        $medicine = $this->medicine();
        $lines = [['kind' => 'MEDICINE', 'catalog_item_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2]];
        $episode = $this->arrive($lines);

        // Requalification en urgence : ouvre Soins ET Médecine.
        app(MarkEpisodeEmergencyAction::class)->execute(
            $episode,
            CatalogModule::Reception,
            $this->receptionist,
        );
        $this->assertSame(2, EpisodeOrientation::query()->where('episode_id', $episode->id)->count());

        $this->actingAs($this->receptionist)->post(
            route('reception.passages.services.store', $episode),
            ['catalog_lines' => $lines, 'payment_choice' => 'LATER'],
        )->assertRedirect();

        $episode->refresh();

        // Le ticket part quand même : le patient doit payer ses médicaments.
        $this->assertSame(1, PharmacyDispense::query()->where('episode_id', $episode->id)->count());

        // Mais le passage reste clinique : personne ne l'a déclaré sorti, et
        // son mode financier n'est pas figé alors que des actes vont suivre.
        $this->assertNotSame(
            EpisodeAdministrativeStatus::PendingSettlement,
            $episode->administrative_status,
        );
        $this->assertNull($episode->financial_mode);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function arrive(array $lines): Episode
    {
        $this->actingAs($this->receptionist)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'first_name' => 'Hanta',
            'last_name' => 'Rasoa',
            'birth_date' => '1992-03-04',
            'sex' => 'F',
            'reception_draft' => ['designation_deferred' => false, 'catalog_lines' => $lines],
        ])->assertCreated();

        return Episode::query()->sole();
    }

    private function setSelfFunded(Episode $episode): void
    {
        $episode->forceFill([
            'financial_mode' => EpisodeFinancialMode::Self,
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $this->receptionist->id,
        ])->save();
    }

    private function service(): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => 'CONS-GEN',
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $this->receptionist->id,
            'updated_by' => $this->receptionist->id,
        ]);

        $this->tariff($item, '20000.00');

        return $item;
    }

    private function medicine(): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PARA-500',
            'name' => 'Paracétamol 500 mg',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => true,
            'stockable' => true,
            'created_by' => $this->receptionist->id,
            'updated_by' => $this->receptionist->id,
        ]);
        $this->tariff($item, '500.00');

        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'minimum_stock' => 2,
            'prescription_required' => false,
            'active' => true,
            'created_by' => $this->receptionist->id,
            'updated_by' => $this->receptionist->id,
        ]);

        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'LOT-A',
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 12,
            'active' => true,
            'created_by' => $this->receptionist->id,
            'updated_by' => $this->receptionist->id,
        ]);

        return $medicine->load('catalogItem');
    }

    private function tariff(CatalogItem $item, string $amount): void
    {
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'tariff_category' => CatalogTariffCategory::Standard,
            'amount' => $amount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test ADR-104',
            'created_by' => $this->receptionist->id,
        ]);
    }
}
