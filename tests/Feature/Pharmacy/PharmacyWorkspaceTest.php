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
use App\Services\Pharmacy\MedicineStockOverviewService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Feature\Pharmacy\Concerns\ReceivesDeliveries;
use Tests\TestCase;

class PharmacyWorkspaceTest extends TestCase
{
    use RefreshDatabase, ReceivesDeliveries;

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

    /**
     * ADR-098 — a product received from a supplier catalogue has stock but
     * no selling price, so the counter is legitimately empty. The screens
     * must be able to say why instead of looking broken.
     */
    public function test_a_product_in_stock_without_a_selling_price_is_counted(): void
    {
        $medicine = $this->medicine('Zinc sulfate 20 mg');
        $this->lot($medicine, 12);

        $summary = app(MedicineStockOverviewService::class)->overview()['summary'];

        $this->assertSame(1, $summary['without_sale_price']);
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

    /**
     * ADR-182 — le stock n'entre que depuis une livraison réceptionnée : le
     * moteur de lots se vérifie par ce chemin-là, le seul qui existe.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function enterReceived(User $actor, Medicine $medicine, string $lot = 'LOT-NEW-001', int $quantity = 12, array $overrides = []): TestResponse
    {
        [$line] = $this->receiveDelivery([[$medicine, $quantity, $lot]]);

        return $this->actingAs($actor)->post('/pharmacy/stock/entries/batch', [
            'lines' => [$this->stockLine($line, $overrides)],
        ]);
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

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Stock/Index')
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

        $this->actingAs($this->pharmacist)->get('/pharmacy/stock')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_view_lots', false)
                ->where('capabilities.can_view_expiration', false)
                ->where('stock.summary.expiring_soon', 0)
                ->where('stock.summary.expired_lots', 0)
                ->where('stock.medicines.0.nearest_expiration', null)
                ->where('stock.medicines.0.lots', []));
    }

    public function test_a_received_line_creates_its_lot_and_an_audited_movement(): void
    {
        $medicine = $this->medicine();

        $this->enterReceived($this->pharmacist, $medicine)
            ->assertRedirect()
            ->assertSessionHas('status');

        $lot = MedicineLot::query()->sole();
        $movement = PharmacyStockMovement::query()->sole();

        $this->assertSame(12, $lot->quantity_on_hand);
        $this->assertSame(PharmacyStockMovementType::Entry, $movement->type);
        $this->assertSame(12, $movement->quantity_delta);
        $this->assertSame(12, $movement->balance_after);
        $this->assertStringStartsWith('Réception ', $movement->origin);
        $this->assertSame('Stock pharmacie', $movement->destination);
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

        $this->enterReceived($this->pharmacist, $medicine, 'LOT-EXISTING', 5)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $movement = PharmacyStockMovement::query()->sole();
        $this->assertSame(15, $lot->fresh()->quantity_on_hand);
        $this->assertSame(PharmacyStockMovementType::Entry, $movement->type);
        $this->assertSame(15, $movement->balance_after);
    }

    public function test_stock_entry_validation_rejects_missing_traceability_and_invalid_quantity(): void
    {
        $medicine = $this->medicine();

        $this->enterReceived($this->pharmacist, $medicine, overrides: [
            'quantity' => 0,
            'lot_number' => '',
            'expires_at' => '',
        ])->assertSessionHasErrors(['lines.0.quantity', 'lines.0.lot_number', 'lines.0.expires_at']);

        $this->assertDatabaseCount('medicine_lots', 0);
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

        $this->enterReceived($user, $medicine)->assertForbidden();

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
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $this->pharmacist->id,
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

        $this->actingAs($this->pharmacist)->get('/pharmacy/dispenses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pharmacy/Dispenses/Index')
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
        $this->enterReceived($this->pharmacist, $medicine)->assertRedirect()->assertSessionHasNoErrors();
        $movement = PharmacyStockMovement::query()->sole();
        $reason = $movement->reason;

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

        $this->assertSame($reason, $movement->fresh()->reason);
        $this->assertDatabaseCount('pharmacy_stock_movements', 1);
        $this->assertGreaterThan(0, AuditLog::query()->where('module', 'pharmacy')->count());
    }
}
