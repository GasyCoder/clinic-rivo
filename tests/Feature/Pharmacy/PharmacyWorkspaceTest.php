<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyStockMovementType;
use App\Enums\PrescriptionStatus;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyStockMovement;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PharmacyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
        ]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->pharmacist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'PHARMACY')->value('id'),
        ]);
    }

    private function medicine(string $name = 'Paracétamol 500 mg'): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-'.str_pad((string) (CatalogItem::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);

        return Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    private function lot(Medicine $medicine, int $quantity = 10, string $number = 'LOT-001'): MedicineLot
    {
        return MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => $number,
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $quantity,
            'active' => true,
            'created_by' => $this->pharmacist->id,
            'updated_by' => $this->pharmacist->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function entryPayload(Medicine $medicine, array $overrides = []): array
    {
        return array_merge([
            'medicine_uuid' => $medicine->uuid,
            'operation' => 'STOCK_INITIAL',
            'lot_number' => 'LOT-NEW-001',
            'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity' => 12,
            'origin' => 'Bon de livraison BL-001',
            'destination' => 'Stock Pharmacie — Mampikony',
            'reason' => 'Stock initial contrôlé à la réception',
        ], $overrides);
    }

    public function test_pharmacy_workspace_requires_the_dynamic_pharmacy_view_permission(): void
    {
        $role = Role::query()->where('code', 'LABORATORY')->firstOrFail();
        $unauthorized = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($unauthorized)->get('/pharmacy')->assertForbidden();
    }

    public function test_workspace_exposes_real_stock_summary_lots_and_expiration_to_an_authorized_pharmacist(): void
    {
        $medicine = $this->medicine();
        $this->lot($medicine, 15);

        $this->actingAs($this->pharmacist)->get('/pharmacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Index')
                ->where('capabilities.can_view_stock', true)
                ->where('capabilities.can_view_lots', true)
                ->where('capabilities.can_view_expiration', true)
                ->where('capabilities.can_print_ticket', true)
                ->where('stock.summary.medicines', 1)
                ->where('stock.summary.quantity_on_hand', 15)
                ->where('stock.summary.available_quantity', 15)
                ->where('stock.medicines.0.name', 'Paracétamol 500 mg')
                ->where('stock.medicines.0.lots.0.lot_number', 'LOT-001'));
    }

    public function test_individual_denies_hide_lot_and_expiration_details_from_the_workspace(): void
    {
        $medicine = $this->medicine();
        $this->lot($medicine);

        foreach (['stock.lots.view', 'stock.expiration.view'] as $name) {
            $this->pharmacist->permissions()->attach(
                Permission::query()->where('name', $name)->value('id'),
                ['effect' => 'deny'],
            );
        }

        $this->actingAs($this->pharmacist)->get('/pharmacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_view_lots', false)
                ->where('capabilities.can_view_expiration', false)
                ->where('stock.summary.expiring_soon', 0)
                ->where('stock.summary.expired_lots', 0)
                ->where('stock.medicines.0.nearest_expiration', null)
                ->where('stock.medicines.0.lots', []));
    }

    public function test_stock_initial_creates_a_lot_and_an_immutable_audited_movement(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine))
            ->assertRedirect()
            ->assertSessionHas('status');

        $lot = MedicineLot::query()->sole();
        $movement = PharmacyStockMovement::query()->sole();

        $this->assertSame(12, $lot->quantity_on_hand);
        $this->assertSame(PharmacyStockMovementType::Opening, $movement->type);
        $this->assertSame(12, $movement->quantity_delta);
        $this->assertSame(12, $movement->balance_after);
        $this->assertSame('Bon de livraison BL-001', $movement->origin);
        $this->assertSame('Stock Pharmacie — Mampikony', $movement->destination);
        $this->assertSame($this->pharmacist->id, $movement->performed_by);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->pharmacist->id,
            'action' => 'create',
            'module' => 'pharmacy',
            'entity_type' => PharmacyStockMovement::class,
            'entity_id' => $movement->id,
            'entity_uuid' => $movement->uuid,
        ]);
    }

    public function test_entry_adds_to_an_existing_lot_without_rewriting_previous_movements(): void
    {
        $medicine = $this->medicine();
        $lot = $this->lot($medicine, 10, 'LOT-EXISTING');

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine, [
                'operation' => 'ENTREE',
                'lot_number' => 'LOT-EXISTING',
                'expires_at' => $lot->expires_at->toDateString(),
                'quantity' => 5,
            ]))
            ->assertRedirect();

        $movement = PharmacyStockMovement::query()->sole();
        $this->assertSame(15, $lot->fresh()->quantity_on_hand);
        $this->assertSame(PharmacyStockMovementType::Entry, $movement->type);
        $this->assertSame(15, $movement->balance_after);
    }

    public function test_stock_entry_validation_rejects_missing_traceability_and_invalid_quantity(): void
    {
        $medicine = $this->medicine();

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine, [
                'quantity' => 0,
                'origin' => '',
                'destination' => '',
                'reason' => '',
            ]))
            ->assertSessionHasErrors(['quantity', 'origin', 'destination', 'reason']);

        $this->assertDatabaseCount('medicine_lots', 0);
        $this->assertDatabaseCount('pharmacy_stock_movements', 0);
    }

    public function test_stock_initial_is_rejected_for_an_existing_lot_without_partial_change(): void
    {
        $medicine = $this->medicine();
        $lot = $this->lot($medicine, 8, 'LOT-EXISTING');

        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine, [
                'lot_number' => 'LOT-EXISTING',
                'expires_at' => $lot->expires_at->toDateString(),
            ]))
            ->assertSessionHasErrors('lot_number');

        $this->assertSame(8, $lot->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('pharmacy_stock_movements', 0);
    }

    public function test_creating_a_new_lot_requires_the_specific_dynamic_permission(): void
    {
        $role = Role::query()->create(['code' => 'PHARMACY_ENTRY_ONLY', 'name' => 'Entrées Pharmacie']);
        $role->permissions()->attach(Permission::query()->whereIn('name', [
            'pharmacy.view',
            'stock.entry',
            'stock.view',
        ])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $medicine = $this->medicine();

        $this->actingAs($user)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine, [
                'operation' => 'ENTREE',
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('medicine_lots', 0);
        $this->assertDatabaseCount('pharmacy_stock_movements', 0);
    }

    public function test_reserved_prescriptions_are_visible_without_any_fabricated_financial_status(): void
    {
        $medicine = $this->medicine();
        $lot = $this->lot($medicine, 10);
        $patient = Patient::query()->create([
            'patient_number' => 'M-26-0999',
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'visit_sequence' => 1,
            'episode_number' => 'M-26-0999-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => now(),
            'created_by' => $this->pharmacist->id,
        ]);
        $consultation = Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $this->pharmacist->id,
            'reason' => 'Consultation documentée',
            'consulted_at' => now(),
        ]);
        $prescription = Prescription::query()->create([
            'consultation_id' => $consultation->id,
            'prescribed_by' => $this->pharmacist->id,
            'status' => PrescriptionStatus::Active,
            'prescribed_at' => now(),
        ]);
        $line = PrescriptionLine::query()->create([
            'prescription_id' => $prescription->id,
            'medicine_id' => $medicine->id,
            'medication_name' => 'Paracétamol 500 mg',
            'quantity' => 4,
        ]);
        MedicineStockReservation::query()->create([
            'prescription_line_id' => $line->id,
            'medicine_lot_id' => $lot->id,
            'quantity' => 4,
            'status' => MedicineStockReservationStatus::Reserved,
            'reserved_at' => now(),
            'reserved_by' => $this->pharmacist->id,
        ]);

        $this->actingAs($this->pharmacist)->get('/pharmacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('queue.summary.prescriptions', 1)
                ->where('queue.summary.reserved_quantity', 4)
                ->where('queue.prescriptions.0.patient.name', 'Soa Rabe')
                ->where('queue.prescriptions.0.episode.number', 'M-26-0999-01')
                ->where('queue.prescriptions.0.lines.0.lots.0.lot_number', 'LOT-001')
                ->missing('queue.prescriptions.0.financial_status')
                ->missing('queue.prescriptions.0.payment'));
    }

    public function test_validated_stock_movements_cannot_be_updated_or_deleted_through_models_or_bulk_queries(): void
    {
        $medicine = $this->medicine();
        $this->actingAs($this->pharmacist)
            ->post('/pharmacy/stock/entries', $this->entryPayload($medicine))
            ->assertRedirect();
        $movement = PharmacyStockMovement::query()->sole();

        foreach ([
            fn () => $movement->update(['reason' => 'Réécriture interdite']),
            fn () => PharmacyStockMovement::query()->whereKey($movement)->update(['reason' => 'Réécriture interdite']),
            fn () => $movement->delete(),
            fn () => PharmacyStockMovement::query()->whereKey($movement)->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Le mouvement immuable aurait dû refuser la mutation.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('Stock initial contrôlé à la réception', $movement->fresh()->reason);
        $this->assertDatabaseCount('pharmacy_stock_movements', 1);
        $this->assertGreaterThan(0, AuditLog::query()->where('module', 'pharmacy')->count());
    }
}
