<?php

namespace Tests\Feature\Pharmacy;

use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineStockPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    private function doctor(bool $withAvailabilityPermission = true): User
    {
        $role = Role::query()->create(['code' => 'MEDICINE', 'name' => 'Médecine']);
        $permissions = [
            'consultations.view',
            'consultations.create',
            'prescriptions.view',
            'prescriptions.create',
            'prescriptions.update',
            'prescriptions.cancel',
            'medicines.view',
        ];

        if ($withAvailabilityPermission) {
            $permissions[] = 'stock.availability.view';
        }

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function acceptedOrientation(User $doctor): EpisodeOrientation
    {
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-0400',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
        ]);
        $this->actingAs($doctor);
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($patient, EpisodePriority::Emergency);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/accept")->assertRedirect();

        return $orientation->fresh();
    }

    /**
     * @param  array<int, array{lot: string, quantity: int, expires_at: string}>  $lots
     */
    private function medicine(User $actor, array $lots, string $name = 'Paracétamol 500 mg'): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'TEST-PHARMACY-'.(CatalogItem::query()->count() + 1),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => 'Paracétamol',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($lots as $lot) {
            MedicineLot::query()->create([
                'medicine_id' => $medicine->id,
                'lot_number' => $lot['lot'],
                'expires_at' => $lot['expires_at'],
                'quantity_on_hand' => $lot['quantity'],
                'active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        return $medicine->load('catalogItem');
    }

    public function test_doctor_sees_usable_stock_and_expiration_but_expired_lots_are_excluded(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $this->medicine($doctor, [
            ['lot' => 'EXPIRED', 'quantity' => 9, 'expires_at' => now()->subDay()->toDateString()],
            ['lot' => 'SOON', 'quantity' => 4, 'expires_at' => now()->addMonth()->toDateString()],
            ['lot' => 'LATER', 'quantity' => 6, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('options.medicines.0.available_quantity', 10)
                ->where('options.medicines.0.expired_lot_count', 1)
                ->where('options.medicines.0.expiring_soon', true)
                ->where('options.medicines.0.nearest_expiration', now()->addMonth()->toDateString())
                ->where('capabilities.can_view_pharmacy_availability', true));
    }

    public function test_insufficient_stock_rolls_back_the_entire_prescription(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'ONLY', 'quantity' => 3, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 4,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertSessionHasErrors('lines.0.quantity');

        $this->assertDatabaseCount('prescriptions', 0);
        $this->assertDatabaseCount('prescription_lines', 0);
        $this->assertDatabaseCount('medicine_stock_reservations', 0);
    }

    public function test_prescription_reserves_stock_in_fefo_order_without_decrementing_physical_stock(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'LATER', 'quantity' => 8, 'expires_at' => now()->addYear()->toDateString()],
            ['lot' => 'SOON', 'quantity' => 2, 'expires_at' => now()->addMonth()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 5,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertRedirect();

        $prescription = Prescription::query()->with('lines.stockReservations.medicineLot')->sole();
        $line = $prescription->lines->sole();
        $this->assertSame(10, $line->stock_available_at_prescription);
        $this->assertSame(5, $line->quantity);
        $this->assertEquals([
            'SOON' => 2,
            'LATER' => 3,
        ], $line->stockReservations->mapWithKeys(fn ($reservation) => [
            $reservation->medicineLot->lot_number => $reservation->quantity,
        ])->all());
        $this->assertSame(10, MedicineLot::query()->sum('quantity_on_hand'));
        $this->assertSame(5, $this->app->make(MedicineStockService::class)
            ->availableCatalog()->sole()['available_quantity']);
    }

    public function test_cancelling_a_prescription_releases_its_stock_reservations(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'CURRENT', 'quantity' => 6, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 4,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertRedirect();
        $prescription = Prescription::query()->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/cancel", [
            'reason' => 'Traitement remplacé après réévaluation',
        ])->assertRedirect();

        $reservation = MedicineStockReservation::query()->sole();
        $this->assertSame(MedicineStockReservationStatus::Released, $reservation->status);
        $this->assertSame($doctor->id, $reservation->released_by);
        $this->assertNotNull($reservation->released_at);
        $this->assertSame(6, $this->app->make(MedicineStockService::class)
            ->availableCatalog()->sole()['available_quantity']);

        $this->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.prescriptions', []));
    }

    public function test_doctor_can_update_quantity_and_posology_with_stock_reallocation(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'CURRENT', 'quantity' => 10, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 4,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertRedirect();
        $prescription = Prescription::query()->with('lines')->sole();
        $line = $prescription->lines->sole();

        $this->put("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}", [
            'lines' => [[
                'id' => $line->id,
                'quantity' => 7,
                'dosage' => '1 g',
                'frequency' => '2 fois par jour',
                'duration' => '5 jours',
                'instructions' => 'Après le repas',
            ]],
        ])->assertRedirect();

        $line->refresh();
        $this->assertSame(7, $line->quantity);
        $this->assertSame('1 g', $line->dosage);
        $this->assertSame(7, MedicineStockReservation::query()
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('quantity'));
        $this->assertSame(3, $this->app->make(MedicineStockService::class)
            ->availableCatalog()->sole()['available_quantity']);
    }

    public function test_failed_prescription_update_keeps_the_previous_line_and_reservation(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'CURRENT', 'quantity' => 5, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 3,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertRedirect();
        $prescription = Prescription::query()->with('lines')->sole();
        $line = $prescription->lines->sole();

        $this->put("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}", [
            'lines' => [[
                'id' => $line->id,
                'quantity' => 6,
                'dosage' => '2 g',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(3, $line->fresh()->quantity);
        $this->assertSame('500 mg', $line->fresh()->dosage);
        $this->assertSame(3, MedicineStockReservation::query()
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('quantity'));
    }

    public function test_prescription_creation_requires_the_specific_stock_availability_permission(): void
    {
        $doctor = $this->doctor(withAvailabilityPermission: false);
        $orientation = $this->acceptedOrientation($doctor);
        $medicine = $this->medicine($doctor, [
            ['lot' => 'CURRENT', 'quantity' => 6, 'expires_at' => now()->addYear()->toDateString()],
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 1,
            ]],
        ])->assertForbidden();

        $this->assertDatabaseCount('prescriptions', 0);
    }
}
