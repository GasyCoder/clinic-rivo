<?php

namespace Tests\Feature\Medicine;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CareOrder;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_orders_two_care_acts_and_the_patient_is_handed_off_to_soins(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation, $consultation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $perfusion = $this->clinicianOrderableItem($doctor, 'PERFUSION', 'Perfusion');

        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [
                ['catalog_item_uuid' => $injection->uuid, 'quantity' => 1],
                ['catalog_item_uuid' => $perfusion->uuid, 'quantity' => 1],
            ],
            'instructions' => 'Surveiller la tolérance.',
            'requires_return_to_medicine' => true,
        ])->assertRedirect();

        $careOrder = CareOrder::query()->sole();
        $this->assertSame('Surveiller la tolérance.', $careOrder->instructions);
        $this->assertTrue($careOrder->requires_return_to_medicine);
        $this->assertSame('PENDING', $careOrder->status->value);
        $this->assertSame($consultation->id, $careOrder->consultation_id);
        $this->assertSame($medicineOrientation->id, $careOrder->source_orientation_id);
        $this->assertSame(2, $careOrder->items()->count());
        $this->assertDatabaseHas('care_order_items', [
            'care_order_id' => $careOrder->id,
            'catalog_item_code_snapshot' => 'INJECTION-IM',
            'catalog_item_name_snapshot' => 'Injection IM',
        ]);

        // Normal patient: exactly one active clinical orientation.
        $this->assertSame('COMPLETED', $medicineOrientation->fresh()->status->value);
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        $this->assertSame('CARE', $careOrientation->destination_module->value);
        $this->assertSame('PENDING', $careOrientation->status->value);
        $this->assertSame('MEDICINE', $careOrientation->source_module->value);

        // Explicit action is the trigger; decision only synchronizes after.
        $this->assertSame('NURSING_CARE', $consultation->fresh()->decision->value);

        // The first Consultation is never touched by this handoff.
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_only_clinician_orderable_care_service_items_can_be_ordered(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $notOrderable = $this->careServiceItem($doctor, 'PANSEMENT-S', 'Pansement simple', clinicianOrderable: false);

        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $notOrderable->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ])->assertSessionHasErrors('items.0.catalog_item_uuid');

        $this->assertDatabaseCount('care_orders', 0);
    }

    public function test_a_medicine_catalog_item_cannot_be_ordered_even_if_marked_clinician_orderable(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $wrongModule = CatalogItem::query()->create([
            'code' => 'FOREIGN-ITEM',
            'name' => 'Hors module Soins',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => false,
            'stockable' => false,
            'clinician_orderable' => true,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $wrongModule->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ])->assertSessionHasErrors('items.0.catalog_item_uuid');
    }

    public function test_a_nurse_cannot_create_a_care_order(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');

        $this->actingAs($nurse)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ])->assertForbidden();

        $this->assertDatabaseCount('care_orders', 0);
    }

    public function test_an_emergency_care_order_keeps_both_medicine_and_care_orientations_active(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($patient, EpisodePriority::Emergency, $doctor);
        $medicineOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $consultation = $this->app->make(AcceptMedicineOrientationAction::class)
            ->execute($medicineOrientation, $doctor)
            ->consultation;
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');

        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ])->assertRedirect();

        // Emergency keeps its existing parallel logic: Médecine stays open.
        $this->assertSame('IN_PROGRESS', $medicineOrientation->fresh()->status->value);
        $careOrder = CareOrder::query()->where('consultation_id', $consultation->id)->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        // Reused the existing parallel Care orientation opened at arrival,
        // rather than creating a duplicate active one.
        $this->assertSame(
            $episode->orientations()->where('destination_module', CatalogModule::Care->value)->sole()->id,
            $careOrientation->id,
        );
    }

    public function test_completing_a_care_order_with_return_to_medicine_opens_a_fresh_consultation_without_touching_the_first(): void
    {
        $doctor = $this->doctor();
        [$episode, $medicineOrientation, $firstConsultation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => true,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);
        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record-and-complete", [
            'procedures' => [[
                'catalog_item_uuid' => $injection->uuid,
                'care_order_item_uuid' => $careOrder->items->sole()->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect(route('care.index'));

        $this->assertSame('COMPLETED', $careOrientation->fresh()->status->value);
        $this->assertSame('COMPLETED', $careOrder->fresh()->status->value);
        $this->assertNotNull($careOrder->fresh()->completed_at);

        $newMedicineOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('id', '!=', $medicineOrientation->id)
            ->sole();
        $this->assertSame('PENDING', $newMedicineOrientation->status->value);

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($newMedicineOrientation, $doctor);

        $this->assertSame(2, Consultation::query()->count());
        $secondConsultation = Consultation::query()
            ->where('episode_orientation_id', $newMedicineOrientation->id)
            ->sole();
        $this->assertNotSame($firstConsultation->id, $secondConsultation->id);
        $this->assertSame(
            $firstConsultation->reason,
            $firstConsultation->fresh()->reason,
        );
    }

    public function test_completing_a_care_order_without_return_to_medicine_settles_administratively(): void
    {
        $doctor = $this->doctor();
        [$episode, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);
        $this->assertSame('IN_CARE', Episode::find($episode->id)->administrative_status->value);

        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record-and-complete", [
            'procedures' => [[
                'catalog_item_uuid' => $injection->uuid,
                'care_order_item_uuid' => $careOrder->items->sole()->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect(route('care.index'));

        $this->assertSame('COMPLETED', $careOrientation->fresh()->status->value);
        $this->assertSame('COMPLETED', $careOrder->fresh()->status->value);
        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);
        $freshEpisode = Episode::find($episode->id);
        $this->assertSame('PENDING_SETTLEMENT', $freshEpisode->administrative_status->value);
        $this->assertSame('OPEN', $freshEpisode->status->value);
        $this->assertDatabaseCount('medical_discharges', 0);
    }

    public function test_a_care_order_cannot_be_completed_without_a_recorded_act(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$careOrientation->uuid}/complete")
            ->assertSessionHasErrors('procedures');

        $this->assertSame('PENDING', $careOrder->fresh()->status->value);
        $this->assertSame('IN_PROGRESS', $careOrientation->fresh()->status->value);
    }

    public function test_soins_sees_the_ordered_acts_on_the_care_orientation_page(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 2]],
            'instructions' => 'À réaliser rapidement.',
            'requires_return_to_medicine' => true,
        ]);
        $careOrientation = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Care->value)
            ->sole();

        $nurse = $this->nurse();
        $this->actingAs($nurse)->get("/care/orientations/{$careOrientation->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->has('careOrders', 1)
                ->where('careOrders.0.status', 'PENDING')
                ->where('careOrders.0.instructions', 'À réaliser rapidement.')
                ->where('careOrders.0.requires_return_to_medicine', true)
                ->has('careOrders.0.items', 1)
                ->where('careOrders.0.items.0.name', 'Injection IM')
                ->where('careOrders.0.items.0.quantity', '2.00')
            );
    }

    public function test_a_billable_ordered_act_bills_normally_once_soins_records_it(): void
    {
        $doctor = $this->doctor();
        [$episode, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        CatalogTariff::query()->create([
            'catalog_item_id' => $injection->id,
            'amount' => '15000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $doctor->id,
        ]);
        Episode::query()->whereKey($episode->id)->update([
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $doctor->id,
        ]);
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrientation = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Care->value)
            ->sole();
        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        $this->assertDatabaseCount('billable_items', 0);

        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $injection->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect();

        $item = BillableItem::query()->sole();
        $this->assertSame('Injection IM', $item->description);
        $this->assertSame('PENDING', $item->status->value);
    }

    public function test_a_multi_item_order_blocks_completion_until_every_item_is_resolved(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $perfusion = $this->clinicianOrderableItem($doctor, 'PERFUSION', 'Perfusion');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [
                ['catalog_item_uuid' => $injection->uuid, 'quantity' => 1],
                ['catalog_item_uuid' => $perfusion->uuid, 'quantity' => 1],
            ],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        $injectionItem = $careOrder->items->firstWhere('catalog_item_code_snapshot', 'INJECTION-IM');
        $perfusionItem = $careOrder->items->firstWhere('catalog_item_code_snapshot', 'PERFUSION');

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        // Only one of the two items realized: completion must be blocked.
        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $injection->uuid,
                'care_order_item_uuid' => $injectionItem->uuid,
                'quantity' => 1,
            ]],
        ]);
        $this->actingAs($nurse)
            ->post("/care/orientations/{$careOrientation->uuid}/complete")
            ->assertSessionHasErrors('care_order');
        $this->assertSame('IN_PROGRESS', $careOrientation->fresh()->status->value);
        $this->assertSame('1.00', $injectionItem->fresh()->realizedQuantity());
        $this->assertSame('0.00', $perfusionItem->fresh()->realizedQuantity());

        // Declining the remaining item resolves the order.
        $this->actingAs($nurse)->post(
            "/care/orientations/{$careOrientation->uuid}/care-order-items/{$perfusionItem->uuid}/not-performed",
            ['reason' => 'Patient a refusé la perfusion.'],
        )->assertRedirect();
        $this->assertNotNull($perfusionItem->fresh()->not_performed_at);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$careOrientation->uuid}/complete")
            ->assertRedirect();
        $this->assertSame('COMPLETED', $careOrientation->fresh()->status->value);
        $this->assertSame('COMPLETED', $careOrder->fresh()->status->value);

        // No billing for the declined act.
        $this->assertDatabaseMissing('billable_items', ['description' => 'Perfusion']);
    }

    public function test_a_partial_quantity_leaves_the_item_unresolved_until_fully_realized(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $perfusion = $this->clinicianOrderableItem($doctor, 'PERFUSION', 'Perfusion');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $perfusion->uuid, 'quantity' => 2]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        $item = $careOrder->items->sole();

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $perfusion->uuid,
                'care_order_item_uuid' => $item->uuid,
                'quantity' => 1,
            ]],
        ]);
        $this->assertSame('1.00', $item->fresh()->realizedQuantity());
        $this->assertSame('1.00', $item->fresh()->remainingQuantity());
        $this->assertFalse($item->fresh()->isResolved());
        $this->actingAs($nurse)
            ->post("/care/orientations/{$careOrientation->uuid}/complete")
            ->assertSessionHasErrors('care_order');

        // A quantity beyond what remains is rejected.
        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $perfusion->uuid,
                'care_order_item_uuid' => $item->uuid,
                'quantity' => 2,
            ]],
        ])->assertSessionHasErrors('procedures.0.quantity');

        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $perfusion->uuid,
                'care_order_item_uuid' => $item->uuid,
                'quantity' => 1,
            ]],
        ]);
        $this->assertSame('2.00', $item->fresh()->realizedQuantity());
        $this->assertSame('0.00', $item->fresh()->remainingQuantity());
        $this->assertTrue($item->fresh()->isResolved());
        $this->actingAs($nurse)
            ->post("/care/orientations/{$careOrientation->uuid}/complete")
            ->assertRedirect();
    }

    public function test_a_care_order_procedure_is_recorded_with_medical_order_source(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        $item = $careOrder->items->sole();

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record", [
            'procedures' => [[
                'catalog_item_uuid' => $injection->uuid,
                'care_order_item_uuid' => $item->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect();

        $this->actingAs($nurse)->get(route('care.orientations.show', $careOrientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->where('careRecord.procedures.0.source', 'MEDICAL_ORDER')
            );
    }

    public function test_no_procedure_reason_does_not_bypass_an_unresolved_care_order(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        // No act realized; a "no procedure" reason is supplied instead of
        // resolving the order. SaveCareRecordAction already reserves this
        // reason to a genuinely unknown-need pathway, which a CareOrder-driven
        // orientation never is — and the CareOrder pathway in
        // CompleteCareAndOrientToMedicineAction never reads no_procedure_reason
        // at all either way. The still-unresolved order cannot be bypassed by it.
        $this->actingAs($nurse)->put("/care/orientations/{$careOrientation->uuid}/record-and-complete", [
            'no_procedure_reason' => 'Patient non disponible pour le moment.',
        ])->assertSessionHasErrors('no_procedure_reason');

        $this->assertSame('IN_PROGRESS', $careOrientation->fresh()->status->value);
        $this->assertSame('PENDING', $careOrder->fresh()->status->value);
    }

    public function test_marking_an_item_not_performed_requires_a_reason(): void
    {
        $doctor = $this->doctor();
        [, $medicineOrientation] = $this->normalMedicineConsultation($doctor);
        $injection = $this->clinicianOrderableItem($doctor, 'INJECTION-IM', 'Injection IM');
        $this->actingAs($doctor)->post("/medicine/orientations/{$medicineOrientation->uuid}/care-orders", [
            'items' => [['catalog_item_uuid' => $injection->uuid, 'quantity' => 1]],
            'requires_return_to_medicine' => false,
        ]);
        $careOrder = CareOrder::query()->sole();
        $careOrientation = EpisodeOrientation::query()->findOrFail($careOrder->care_orientation_id);
        $item = $careOrder->items->sole();

        $nurse = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($careOrientation, $nurse);

        $this->actingAs($nurse)->post(
            "/care/orientations/{$careOrientation->uuid}/care-order-items/{$item->uuid}/not-performed",
            [],
        )->assertSessionHasErrors('reason');

        $this->assertNull($item->fresh()->not_performed_at);
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);
        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view', 'prescriptions.create',
            'medicines.view', 'stock.availability.view', 'medical_discharge.create',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'care_orders.create', 'care_orders.view',
        ];

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function nurse(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'Soins']);
        $permissions = [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
            'patients.medical_history.view', 'care_orders.view',
        ];

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation, 2: Consultation} */
    private function normalMedicineConsultation(User $doctor): array
    {
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $consultationItem = CatalogItem::query()->create([
            'code' => 'CONSULT-GEN',
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $consultationItem->uuid,
            'quantity' => 1,
        ]], $doctor);
        $medicineOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $accepted = $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicineOrientation, $doctor);

        return [$episode->fresh(), $medicineOrientation->fresh(), $accepted->consultation];
    }

    private function clinicianOrderableItem(User $actor, string $code, string $name): CatalogItem
    {
        return $this->careServiceItem($actor, $code, $name, clinicianOrderable: true);
    }

    private function careServiceItem(User $actor, string $code, string $name, bool $clinicianOrderable): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'clinician_orderable' => $clinicianOrderable,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
