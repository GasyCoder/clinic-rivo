<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
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
 * ADR-110 — une compresse stérile n'a pas de dose.
 *
 * Ce que ces tests protègent : la **forme du référentiel** décide si la dose
 * est exigée, jamais le libellé ni le code du produit (ADR-052). Et la règle
 * vit côté serveur : masquer le champ dans Vue n'aurait rien réglé pour un
 * appelant qui poste directement.
 */
class PrescriptionConsumableDosageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consumable_line_is_accepted_without_a_dose(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->consultation($doctor);
        $compresses = $this->medicine($doctor, MedicineForm::ParapharmacyConsumable, 'Compresses stériles');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $compresses->catalogItem->uuid,
                    'quantity' => 10,
                    'dosage' => null,
                    'frequency' => '2 fois/jour',
                    'duration' => '5 jours',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $line = $orientation->consultation->prescriptions()->sole()->lines()->sole();

        // Et l'absence reste une absence : aucune dose n'est fabriquée pour
        // remplir la colonne (ADR-077).
        $this->assertNull($line->dosage);
        $this->assertSame(10, $line->quantity);
    }

    /**
     * Le champ masqué par `v-if` reste dans l'objet du formulaire et part à
     * vide : c'est exactement ce que le navigateur envoie.
     */
    public function test_an_empty_string_dose_is_accepted_for_a_consumable(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->consultation($doctor);
        $gloves = $this->medicine($doctor, MedicineForm::ParapharmacyConsumable, 'Gants d’examen');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $gloves->catalogItem->uuid,
                    'quantity' => 4,
                    'dosage' => '',
                    'frequency' => '2 fois/jour',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(
            $orientation->consultation->prescriptions()->sole()->lines()->sole()->dosage,
        );
    }

    /** Un médicament qui se dose l'exige toujours : la règle n'est pas levée. */
    public function test_a_dosed_medicine_still_requires_its_dose(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->consultation($doctor);
        $amoxicillin = $this->medicine($doctor, MedicineForm::Tablet, 'Amoxicilline 500 mg');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $amoxicillin->catalogItem->uuid,
                    'quantity' => 21,
                    'dosage' => null,
                    'frequency' => '3 fois/jour',
                    'duration' => '7 jours',
                ]],
            ])
            ->assertSessionHasErrors([
                'lines.0.dosage' => 'Indiquez la dose (mode d’emploi) de ce médicament.',
            ]);

        $this->assertSame(0, $orientation->consultation->prescriptions()->count());
    }

    /**
     * La règle est propre à **chaque ligne**, pas à l'ordonnance : une règle
     * `lines.*` ne peut pas lire la forme du produit de sa propre ligne, et
     * c'est précisément pourquoi `Rule::forEach` est utilisé.
     */
    public function test_the_rule_is_resolved_line_by_line(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->consultation($doctor);
        $compresses = $this->medicine($doctor, MedicineForm::ParapharmacyConsumable, 'Compresses stériles');
        $amoxicillin = $this->medicine($doctor, MedicineForm::Tablet, 'Amoxicilline 500 mg');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [
                    [
                        'manual' => false,
                        'medicine_uuid' => $compresses->catalogItem->uuid,
                        'quantity' => 10,
                        'dosage' => null,
                        'frequency' => '2 fois/jour',
                    ],
                    [
                        'manual' => false,
                        'medicine_uuid' => $amoxicillin->catalogItem->uuid,
                        'quantity' => 21,
                        'dosage' => null,
                        'frequency' => '3 fois/jour',
                    ],
                ],
            ])
            ->assertSessionHasErrors('lines.1.dosage')
            ->assertSessionDoesntHaveErrors('lines.0.dosage');
    }

    /**
     * Une ligne manuelle (ADR-037) ne référence aucune forme : rien ne permet
     * de savoir qu'elle ne se dose pas, et la dose reste donc exigée plutôt
     * que devinée.
     */
    public function test_a_manual_line_still_requires_its_dose(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => true,
                    'medication_name' => 'Compresses stériles',
                    'quantity' => 10,
                    'dosage' => null,
                    'frequency' => '2 fois/jour',
                ]],
            ])
            ->assertSessionHasErrors('lines.0.dosage');
    }

    /**
     * La liste des formes non dosées est portée par l'enum, pas recopiée : la
     * même source sert le serveur et l'éditeur de ligne
     * (`resources/js/utilities/posology.js`).
     */
    public function test_only_the_parapharmacy_consumable_form_is_undosed(): void
    {
        $this->assertSame(
            [MedicineForm::ParapharmacyConsumable->value],
            MedicineForm::undosedValues(),
        );

        $this->assertFalse(MedicineForm::ParapharmacyConsumable->isDosed());
        $this->assertTrue(MedicineForm::Tablet->isDosed());
        $this->assertTrue(MedicineForm::Injectable->isDosed());
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

    private function consultation(User $doctor): EpisodeOrientation
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

        return $orientation->fresh();
    }

    private function medicine(User $doctor, MedicineForm $form, string $name): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'MED-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'unité',
            'billable' => true,
            'stockable' => true,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => $form,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'LOT-'.uniqid(),
            'quantity_on_hand' => 500,
            'expires_at' => now()->addYear(),
            'created_by' => $doctor->id,
        ]);

        return $medicine->fresh('catalogItem');
    }
}
