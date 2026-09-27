<?php

namespace Tests\Feature\Maternity;

use App\Actions\Billing\RecordBillableItemAction;
use App\Actions\Catalog\SyncCareActConsumablesAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CareActConsumable;
use App\Models\CareConsumableRequest;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\MaternityProcedure;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Care\CareConsumableDirectory;
use App\Services\Catalog\CatalogActor;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-141 / ADR-142 — un acte Maternité enregistré alimente le compte du patient,
 * et le matériel utilisé part à la Pharmacie par le circuit des consommables Soins.
 * Le patient règle à la Caisse ; la sage-femme ne voit jamais un prix.
 */
class MaternityBillingAndMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    // ── Facturation de l'acte ────────────────────────────────────────────

    public function test_a_recorded_act_is_billed_at_the_server_side_tariff(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-CPN', 'Consultation prénatale', 12000);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 2]])
            ->assertSessionHasNoErrors();

        $procedure = MaternityProcedure::query()->sole();
        $billable = BillableItem::query()->sole();
        $this->assertSame($billable->id, $procedure->billable_item_id);
        $this->assertSame('OWN', $procedure->billing_origin);
        $this->assertSame(BillableItemStatus::Pending, $billable->status);
        // Le prix vient du référentiel, jamais du navigateur : 2 × 12 000.
        $this->assertEquals(24000, $billable->total_amount);
        $this->assertSame($episode->id, $billable->episode_id);
    }

    public function test_an_act_already_billed_at_reception_is_linked_not_billed_twice(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-CPN', 'Consultation prénatale', 12000);
        $planned = $this->billedAtReception($episode, $act, $midwife);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]])
            ->assertSessionHasNoErrors();

        $procedure = MaternityProcedure::query()->sole();
        $this->assertSame(1, BillableItem::query()->count());
        $this->assertSame($planned->id, $procedure->billable_item_id);
        $this->assertSame('PLANNED', $procedure->billing_origin);

        // Un second acte identique est un second acte : il se facture.
        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, BillableItem::query()->count());
        $this->assertSame('OWN', MaternityProcedure::query()->latest('id')->first()->billing_origin);
    }

    public function test_a_missing_tariff_never_blocks_the_act_and_is_reported_without_an_amount(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-CPN', 'Consultation prénatale', null);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MaternityProcedure::query()->count());
        $this->assertSame(0, BillableItem::query()->count());

        $page = $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")->viewData('page')['props'];
        $billing = $page['record']['procedures'][0]['billing'];

        $this->assertSame('NOT_BILLED', $billing['state']);
        $this->assertTrue($billing['needs_attention']);
        // La sage-femme ne voit jamais un prix (ADR-036).
        $this->assertArrayNotHasKey('amount', $billing);
    }

    public function test_removing_an_act_cancels_what_maternity_billed_but_never_what_reception_billed(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $own = $this->act($midwife, 'MAT-A', 'Acte A', 5000);
        $planned = $this->act($midwife, 'MAT-B', 'Acte B', 7000);
        $receptionItem = $this->billedAtReception($episode, $planned, $midwife);

        $this->postBatch($midwife, $orientation, [
            ['catalog_item_uuid' => $own->uuid, 'quantity' => 1],
            ['catalog_item_uuid' => $planned->uuid, 'quantity' => 1],
        ])->assertSessionHasNoErrors();

        $ownProcedure = MaternityProcedure::query()->where('procedure_code', 'MAT-A')->sole();
        $plannedProcedure = MaternityProcedure::query()->where('procedure_code', 'MAT-B')->sole();

        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$ownProcedure->uuid}")
            ->assertSessionHasNoErrors();
        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$plannedProcedure->uuid}")
            ->assertSessionHasNoErrors();

        $this->assertSame(BillableItemStatus::Cancelled, BillableItem::query()->findOrFail($ownProcedure->billable_item_id)->status);
        // La facturation de la Réception n'appartient pas à la Maternité.
        $this->assertSame(BillableItemStatus::Pending, $receptionItem->fresh()->status);
    }

    public function test_an_act_removed_frees_the_reception_billing_for_the_next_identical_act(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-B', 'Acte B', 7000);
        $receptionItem = $this->billedAtReception($episode, $act, $midwife);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]]);
        $first = MaternityProcedure::query()->sole();
        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$first->uuid}");

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]]);

        // Rattaché de nouveau à la facturation de la Réception : jamais un doublon.
        $this->assertSame(1, BillableItem::query()->count());
        $this->assertSame($receptionItem->id, MaternityProcedure::query()->sole()->billable_item_id);
    }

    public function test_an_invoiced_act_is_never_unwound_from_the_maternity(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-A', 'Acte A', 5000);
        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]]);
        $procedure = MaternityProcedure::query()->sole();
        BillableItem::query()->whereKey($procedure->billable_item_id)->update(['status' => BillableItemStatus::Invoiced->value]);

        // La quantité ne bouge plus d'ici : le montant appartient à la Réception/Caisse.
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", ['quantity' => 3])
            ->assertSessionHasErrors('quantity');
        $this->assertEquals(1, $procedure->fresh()->quantity);

        // Retirer l'acte de la liste ne détricote pas la facture.
        $this->actingAs($midwife)->delete("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}")
            ->assertSessionHasNoErrors();
        $this->assertSame(BillableItemStatus::Invoiced, BillableItem::query()->sole()->status);
    }

    public function test_changing_the_quantity_rebills_what_maternity_billed_itself(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-A', 'Acte A', 5000);
        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]]);
        $procedure = MaternityProcedure::query()->sole();
        $oldItem = $procedure->billable_item_id;

        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", ['quantity' => 3])
            ->assertSessionHasNoErrors();

        $procedure->refresh();
        $this->assertNotSame($oldItem, $procedure->billable_item_id);
        $this->assertSame(BillableItemStatus::Cancelled, BillableItem::query()->findOrFail($oldItem)->status);
        $this->assertEquals(15000, BillableItem::query()->findOrFail($procedure->billable_item_id)->total_amount);

        // Corriger seulement la précision ne touche à aucune facturation.
        $current = $procedure->billable_item_id;
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/procedures/{$procedure->uuid}", ['quantity' => 3, 'notes' => 'Précision'])
            ->assertSessionHasNoErrors();
        $this->assertSame($current, $procedure->fresh()->billable_item_id);
    }

    // ── Matériel utilisé → Pharmacie ─────────────────────────────────────

    public function test_material_used_is_transmitted_to_the_pharmacy_and_billed_separately(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-A', 'Acte A', 5000);
        $gloves = $this->product($midwife, 'Gants stériles', MedicineForm::ParapharmacyConsumable, 20, 800);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]], [
            ['medicine_uuid' => $gloves->uuid, 'quantity' => 4],
        ], 'Pour l’accouchement')->assertSessionHasNoErrors();

        $request = CareConsumableRequest::query()->with('lines')->sole();
        $this->assertSame('MATERNITY', $request->source_module);
        $this->assertSame($orientation->id, $request->care_orientation_id);
        $this->assertSame($episode->maternityRecord->id, $request->maternity_record_id);
        $this->assertNull($request->care_record_id);
        $this->assertSame(CareConsumableRequestStatus::Pending, $request->status);
        $this->assertSame('Pour l’accouchement', $request->notes);

        // Facturé en plus de l'acte, au tarif serveur : 5 000 + 4 × 800.
        $this->assertEquals(8200, BillableItem::query()->sum('total_amount'));
        $this->assertNotNull($request->lines->sole()->billable_item_id);
        // La sortie de stock est le travail de la Pharmacie.
        $this->assertSame(0, PharmacyStockMovement::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'maternity.consumables.request']);
    }

    public function test_material_can_be_transmitted_without_any_act(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $product = $this->product($midwife, 'Compresses stériles', MedicineForm::ParapharmacyConsumable, 10, 500);

        $this->postBatch($midwife, $orientation, [], [['medicine_uuid' => $product->uuid, 'quantity' => 2]])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, MaternityProcedure::query()->count());
        $this->assertSame(1, CareConsumableRequest::query()->count());
    }

    public function test_an_empty_submission_is_refused(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);

        $this->postBatch($midwife, $orientation, [])->assertSessionHasErrors('procedures');
    }

    /**
     * Le DIU, l'implant et l'injectable ne sont pas de la parapharmacie : la Maternité
     * ne les choisit pas librement, c'est la configuration de l'acte qui les rend
     * déclarables — jamais une déduction (ADR-142).
     */
    public function test_maternity_can_declare_a_product_configured_for_one_of_its_acts_but_not_any_medicine(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-DIU', 'Insertion DIU', 15000);
        $diu = $this->product($midwife, 'DIU cuivre', MedicineForm::Other, 5, 20000);
        $tablet = $this->product($midwife, 'Paracétamol 500 mg', MedicineForm::Tablet, 50, 100);
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id, 'medicine_id' => $diu->id, 'default_quantity' => 1, 'position' => 0,
            'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);

        $this->postBatch($midwife, $orientation, [], [['medicine_uuid' => $tablet->uuid, 'quantity' => 1]])
            ->assertSessionHasErrors('consumables');
        $this->assertSame(0, CareConsumableRequest::query()->count());

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]], [['medicine_uuid' => $diu->uuid, 'quantity' => 1]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CareConsumableRequest::query()->count());
    }

    public function test_soins_never_gain_the_products_configured_for_the_maternity(): void
    {
        $midwife = $this->midwife();
        $act = $this->act($midwife, 'MAT-DIU', 'Insertion DIU', 15000);
        $diu = $this->product($midwife, 'DIU cuivre', MedicineForm::Other, 5, 20000);
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id, 'medicine_id' => $diu->id, 'default_quantity' => 1, 'position' => 0,
            'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);

        $this->assertSame([], CareConsumableDirectory::eligibleMedicines(CatalogModule::Care)->pluck('uuid')->all());
        $this->assertSame([$diu->uuid], CareConsumableDirectory::eligibleMedicines(CatalogModule::Maternity)->pluck('uuid')->all());
    }

    public function test_declaring_material_needs_its_own_permission_and_is_not_ignored_silently(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $product = $this->product($midwife, 'Gants', MedicineForm::ParapharmacyConsumable, 10, 500);
        $midwife->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'care_consumables.request')->value('id') => ['effect' => 'deny'],
        ]);

        $this->postBatch($midwife->fresh(), $orientation, [], [['medicine_uuid' => $product->uuid, 'quantity' => 1]])
            ->assertForbidden();
        $this->assertSame(0, CareConsumableRequest::query()->count());
    }

    public function test_one_refused_material_line_records_nothing_at_all(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-A', 'Acte A', 5000);
        $tablet = $this->product($midwife, 'Paracétamol 500 mg', MedicineForm::Tablet, 50, 100);

        $this->postBatch($midwife, $orientation, [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]], [
            ['medicine_uuid' => $tablet->uuid, 'quantity' => 1],
        ])->assertSessionHasErrors('consumables');

        // Ni l'acte, ni sa facturation, ni la demande : tout, ou rien.
        $this->assertSame(0, MaternityProcedure::query()->count());
        $this->assertSame(0, BillableItem::query()->count());
    }

    public function test_the_pharmacy_serves_maternity_material_and_labels_its_origin(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $gloves = $this->product($midwife, 'Gants stériles', MedicineForm::ParapharmacyConsumable, 20, 800);
        $this->postBatch($midwife, $orientation, [], [['medicine_uuid' => $gloves->uuid, 'quantity' => 4]]);
        $request = CareConsumableRequest::query()->with('lines')->sole();
        $pharmacist = $this->pharmacist();

        $queue = $this->actingAs($pharmacist)->get('/pharmacy/care-consumables')->viewData('page')['props']['careConsumables'];
        $this->assertSame('Maternité', $queue['requests'][0]['source_label']);

        $this->actingAs($pharmacist)->post("/pharmacy/care-consumables/{$request->uuid}/serve", [
            'lines' => [['uuid' => $request->lines->sole()->uuid, 'quantity' => 4]],
        ])->assertRedirect();

        $this->assertSame(16, MedicineLot::query()->sole()->quantity_on_hand);
        $movement = PharmacyStockMovement::query()->sole();
        $this->assertStringStartsWith('Maternité — patient', $movement->destination);
        $this->assertStringContainsString('Consommables Maternité', $movement->reason);
    }

    public function test_a_maternity_request_can_be_cancelled_until_the_pharmacy_serves_it(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $gloves = $this->product($midwife, 'Gants stériles', MedicineForm::ParapharmacyConsumable, 20, 800);
        $this->postBatch($midwife, $orientation, [], [['medicine_uuid' => $gloves->uuid, 'quantity' => 4]]);
        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/consumables/{$request->uuid}/cancel", ['reason' => 'Saisi par erreur'])
            ->assertSessionHasNoErrors();

        $this->assertSame(CareConsumableRequestStatus::Cancelled, $request->fresh()->status);
        // Ce qui n'était pas encore sur une facture est retiré du compte du patient.
        $this->assertSame(BillableItemStatus::Cancelled, BillableItem::query()->sole()->status);
    }

    public function test_a_request_of_another_orientation_cannot_be_cancelled_from_this_one(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        [, $other] = $this->inProgress($midwife);
        $gloves = $this->product($midwife, 'Gants stériles', MedicineForm::ParapharmacyConsumable, 20, 800);
        $this->postBatch($midwife, $other, [], [['medicine_uuid' => $gloves->uuid, 'quantity' => 1]]);
        $request = CareConsumableRequest::query()->sole();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/consumables/{$request->uuid}/cancel", ['reason' => 'Autre passage'])
            ->assertNotFound();
    }

    // ── Ce que l'écran reçoit ────────────────────────────────────────────

    public function test_the_page_serves_the_catalogue_the_suggestions_and_the_requests(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $act = $this->act($midwife, 'MAT-DIU', 'Insertion DIU', 15000);
        $diu = $this->product($midwife, 'DIU cuivre', MedicineForm::Other, 5, 20000);
        CareActConsumable::query()->create([
            'catalog_item_id' => $act->id, 'medicine_id' => $diu->id, 'default_quantity' => 1, 'position' => 0,
            'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);

        $props = $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")->viewData('page')['props'];

        $this->assertTrue($props['capabilities']['can_request_consumables']);
        $this->assertSame([$diu->uuid], collect($props['consumableCatalog'])->pluck('medicine_uuid')->all());
        $catalogAct = collect($props['procedureCatalog'])->firstWhere('code', 'MAT-DIU');
        $this->assertSame($diu->uuid, $catalogAct['default_consumables'][0]['medicine_uuid']);
        $this->assertSame(1, $catalogAct['default_consumables'][0]['quantity']);
    }

    public function test_an_account_without_the_request_right_receives_no_catalogue_and_no_suggestion(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->inProgress($midwife);
        $midwife->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'care_consumables.request')->value('id') => ['effect' => 'deny'],
        ]);

        $props = $this->actingAs($midwife->fresh())->get("/maternity/orientations/{$orientation->uuid}")->viewData('page')['props'];

        $this->assertFalse($props['capabilities']['can_request_consumables']);
        $this->assertSame([], $props['consumableCatalog']);
        $this->assertSame([], collect($props['procedureCatalog'])->pluck('default_consumables')->flatten(1)->all());
    }

    // ── Configuration du référentiel ─────────────────────────────────────

    public function test_the_administration_can_configure_any_stockable_product_for_a_maternity_act(): void
    {
        $admin = $this->midwife();
        $admin->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'catalog.items.update')->value('id') => ['effect' => 'allow'],
        ]);
        $act = $this->act($admin, 'MAT-DIU', 'Insertion DIU', 15000);
        $tablet = $this->product($admin, 'Implanon', MedicineForm::Other, 5, 90000);

        app(SyncCareActConsumablesAction::class)->execute($act, [['medicine_uuid' => $tablet->uuid, 'default_quantity' => 1]], CatalogActor::fromUser($admin->fresh()));

        $this->assertSame(1, CareActConsumable::query()->where('catalog_item_id', $act->id)->count());
    }

    public function test_a_care_act_still_only_accepts_parapharmacy(): void
    {
        $admin = $this->midwife();
        $admin->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'catalog.items.update')->value('id') => ['effect' => 'allow'],
        ]);
        $careAct = CatalogItem::query()->create([
            'code' => 'CARE-X', 'name' => 'Pansement', 'type' => CatalogItemType::Service, 'module' => CatalogModule::Care,
            'unit' => 'soin', 'billable' => true, 'stockable' => false, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $implant = $this->product($admin, 'Implanon', MedicineForm::Other, 5, 90000);

        $this->expectException(ValidationException::class);
        app(SyncCareActConsumablesAction::class)->execute($careAct, [['medicine_uuid' => $implant->uuid, 'default_quantity' => 1]], CatalogActor::fromUser($admin->fresh()));
    }

    // ── Aides ────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $procedures
     * @param  array<int, array<string, mixed>>  $consumables
     */
    private function postBatch(User $user, EpisodeOrientation $orientation, array $procedures, array $consumables = [], ?string $notes = null)
    {
        return $this->actingAs($user)->post("/maternity/orientations/{$orientation->uuid}/procedures/batch", array_filter([
            'procedures' => $procedures ?: null,
            'consumables' => $consumables ?: null,
            'consumable_notes' => $notes,
        ], fn ($value) => $value !== null));
    }

    private function act(User $actor, string $code, string $name, ?int $price): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        if ($price !== null) {
            $this->tariff($item, $actor, $price);
        }

        return $item;
    }

    private function product(User $actor, string $name, MedicineForm $form, int $stock, int $price): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.(CatalogItem::query()->count() + 1), 'name' => $name, 'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy, 'unit' => 'unité', 'billable' => true, 'stockable' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $this->tariff($item, $actor, $price);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id, 'generic_name' => $name, 'form' => $form, 'active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id, 'lot_number' => 'L-'.$medicine->id, 'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $stock, 'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        return $medicine->load('catalogItem');
    }

    private function tariff(CatalogItem $item, User $actor, int $amount): CatalogTariff
    {
        return CatalogTariff::query()->create([
            'catalog_item_id' => $item->id, 'amount' => number_format($amount, 2, '.', ''), 'currency' => 'MGA',
            'effective_from' => now(), 'active_key' => 'CURRENT', 'change_reason' => 'Tarif de test', 'created_by' => $actor->id,
        ]);
    }

    /** Ce que la Réception a déjà planifié et facturé à l'arrivée (ADR-068, ADR-109). */
    private function billedAtReception(Episode $episode, CatalogItem $act, User $actor): BillableItem
    {
        EpisodeServiceRequest::query()->create([
            'episode_id' => $episode->id, 'catalog_item_id' => $act->id, 'catalog_item_uuid' => $act->uuid,
            'catalog_code' => $act->code, 'designation' => $act->name, 'module' => CatalogModule::Maternity,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect, 'unit' => 'acte', 'unit_price' => 7000,
            'quantity' => 1, 'created_by' => $actor->id,
        ]);

        return app(RecordBillableItemAction::class)->execute($episode->fresh(), [
            'catalog_item_uuid' => $act->uuid, 'quantity' => 1,
        ], $actor);
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function inProgress(User $midwife): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1996-05-12', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        Episode::query()->whereKey($episode->id)->update([
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $midwife->id,
        ]);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept");

        return [$episode->fresh(), $orientation->fresh()];
    }

    private function pharmacist(): User
    {
        $role = Role::query()->where('code', 'PHARMACY')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function midwife(): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);
        $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['effect' => 'allow']],
        )->all());

        return $user->fresh(['role', 'professionalProfile']);
    }
}
