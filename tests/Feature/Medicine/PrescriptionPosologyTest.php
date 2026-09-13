<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\AdministrationRoute;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Posology that reads like an instruction, and the route that says how the
 * medicine is given.
 *
 * The guarantee under test: a prescription never reaches the person
 * administering it as a bare number. "500" is not a dose; "500 mg, orale,
 * 3 fois/jour" is.
 */
class PrescriptionPosologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_route_of_administration(): void
    {
        $doctor = $this->doctor();
        [, $orientation, $medicine] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'quantity' => 21,
                    'dosage' => '500 mg',
                    'route' => AdministrationRoute::Oral->value,
                    'frequency' => '3 fois/jour',
                    'duration' => '7 jours',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $line = $orientation->consultation->prescriptions()->sole()->lines()->sole();

        $this->assertSame(AdministrationRoute::Oral, $line->route);
        $this->assertSame('500 mg', $line->dosage);
        $this->assertSame('3 fois/jour', $line->frequency);
    }

    /** The route stays optional: not every line needs one stated. */
    public function test_the_route_stays_optional(): void
    {
        $doctor = $this->doctor();
        [, $orientation, $medicine] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'quantity' => 10,
                    'dosage' => '1 comprimé',
                    'frequency' => 'matin et soir',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($orientation->consultation->prescriptions()->sole()->lines()->sole()->route);
    }

    public function test_an_unknown_route_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $orientation, $medicine] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'quantity' => 1,
                    'dosage' => '500 mg',
                    'route' => 'PAR_LA_PENSEE',
                    'frequency' => '1 fois/jour',
                ]],
            ])
            ->assertSessionHasErrors('lines.0.route');
    }

    /**
     * The screen must never have to guess a unit: the posology reaches it
     * already composed, route included.
     */
    public function test_the_screen_receives_the_posology_already_composed(): void
    {
        $doctor = $this->doctor();
        [, $orientation, $medicine] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => false,
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 21,
                'dosage' => '500 mg',
                'route' => AdministrationRoute::Oral->value,
                'frequency' => '3 fois/jour',
                'duration' => '7 jours',
            ]],
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.prescriptions.0.lines.0.posology', '500 mg · orale · 3 fois/jour · 7 jours')
                ->where('consultation.prescriptions.0.lines.0.route_label', 'Orale')
            );
    }

    /** Every route is offered to the screen, none hard-coded in Vue. */
    public function test_the_available_routes_are_served_by_the_server(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('options.administration_routes', count(AdministrationRoute::cases()))
                ->where('options.administration_routes.0.short_label', 'orale')
            );
    }

    /**
     * §16 — prescribing is a decision, dispensing is a stock movement. The
     * physical quantity must not move because a doctor prescribed.
     */
    public function test_prescribing_reserves_without_decrementing_the_physical_stock(): void
    {
        $doctor = $this->doctor();
        [, $orientation, $medicine, $lot] = $this->consultationWithStock($doctor);
        $before = $lot->quantity_on_hand;

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => false,
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 5,
                'dosage' => '500 mg',
                'route' => AdministrationRoute::Oral->value,
                'frequency' => '1 fois/jour',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $before,
            $lot->fresh()->quantity_on_hand,
            'Only a Pharmacy dispensing may move the physical quantity.',
        );
    }

    /** §13 — a step declared unnecessary is never offered as the way back. */
    public function test_a_skipped_paraclinical_step_is_not_offered_as_the_previous_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultationWithStock($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/complementary-exams", [
            'required' => false,
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The screen reads the status and decides from it; the test
                // pins the fact it depends on.
                ->where('consultation.steps.paraclinique.status', 'SKIPPED')
            );
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view', 'diagnoses.view',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'medicines.view', 'stock.availability.view',
            'laboratory_orders.view', 'imaging_orders.view',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation, 2: Medicine, 3: MedicineLot} */
    private function consultationWithStock(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $consultationItem = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
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
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        $medicineItem = CatalogItem::query()->create([
            'code' => 'MED-'.uniqid(),
            'name' => 'Amoxicilline 500 mg',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'gélule',
            'billable' => true,
            'stockable' => true,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $medicineItem->id,
            'generic_name' => 'Amoxicilline',
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $lot = MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'LOT-'.uniqid(),
            'quantity_on_hand' => 100,
            'expires_at' => now()->addYear(),
            'created_by' => $doctor->id,
        ]);

        return [$episode->fresh(), $orientation->fresh(), $medicine->fresh('catalogItem'), $lot];
    }
}
