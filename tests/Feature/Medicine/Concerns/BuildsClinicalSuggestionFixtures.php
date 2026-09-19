<?php

namespace Tests\Feature\Medicine\Concerns;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\DiagnosticCatalog;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * ADR-111 — un médecin, une consultation en cours, un diagnostic du
 * référentiel, un médicament en stock, un protocole : de quoi exercer les
 * deux sources de proposition sans rien simuler de ce qu'elles lisent.
 */
trait BuildsClinicalSuggestionFixtures
{
    // --- fixtures -------------------------------------------------------

    protected function doctor(bool $manage = true): User
    {
        $role = Role::query()->firstOrCreate(['code' => $manage ? 'MEDICINE' : 'MEDICINE_READER'], ['name' => 'Médecine']);

        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view', 'diagnoses.view', 'diagnoses.create',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'medicines.view', 'stock.availability.view',
            'laboratory_orders.view', 'imaging_orders.view', 'clinical_protocols.view',
        ];

        if ($manage) {
            $permissions[] = 'clinical_protocols.manage';
        }

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: EpisodeOrientation, 1: Consultation} */
    protected function consultation(User $doctor, ?string $birthDate): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => $birthDate,
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
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
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        $orientation = $episode->orientations()->where('destination_module', CatalogModule::Medicine->value)->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        $orientation = $orientation->fresh();

        return [$orientation, $orientation->consultation()->firstOrFail()];
    }

    protected function diagnostic(string $code, string $name): DiagnosticCatalog
    {
        return DiagnosticCatalog::query()->create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    protected function medicine(User $actor, string $name, string $generic): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'MED-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => true,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $generic,
            'form' => MedicineForm::Tablet,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'LOT-'.uniqid(),
            'quantity_on_hand' => 200,
            'expires_at' => now()->addYear(),
            'created_by' => $actor->id,
        ]);

        return $medicine->fresh('catalogItem');
    }

    /**
     * @param  array<int, string>  $indications
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function protocol(User $doctor, DiagnosticCatalog $diagnostic, array $indications, array $attributes = [], array $lines = []): ClinicalProtocol
    {
        $protocol = ClinicalProtocol::query()->create([
            'diagnostic_catalog_id' => $diagnostic->id,
            'name' => $attributes['name'] ?? "{$diagnostic->name} — adulte",
            'indications' => $indications,
            'min_age_years' => $attributes['min_age_years'] ?? null,
            'max_age_years' => $attributes['max_age_years'] ?? null,
            'is_active' => true,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        foreach ($lines as $position => $line) {
            $medicine = Medicine::query()->whereHas('catalogItem', fn ($q) => $q->where('uuid', $line['medicine_uuid']))->sole();
            $protocol->lines()->create([
                'medicine_id' => $medicine->id,
                'dosage' => $line['dosage'] ?? null,
                'frequency' => $line['frequency'],
                'duration' => $line['duration'] ?? null,
                'sort_order' => $position,
            ]);
        }

        return $protocol;
    }

    protected function recordDiagnosis(User $doctor, EpisodeOrientation $orientation, DiagnosticCatalog $diagnostic): void
    {
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
                'type' => 'FINAL',
                'diagnostic_catalog_uuid' => $diagnostic->uuid,
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    protected function payload(DiagnosticCatalog $diagnostic, Medicine $medicine, array $overrides = []): array
    {
        return array_merge([
            'diagnostic_catalog_uuid' => $diagnostic->uuid,
            'name' => 'Paludisme simple — adulte',
            'indications' => ['Fièvre'],
            'is_active' => true,
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'dosage' => '80 mg',
                'frequency' => '3 fois/jour',
                'duration' => '3 jours',
            ]],
        ], $overrides);
    }
}
