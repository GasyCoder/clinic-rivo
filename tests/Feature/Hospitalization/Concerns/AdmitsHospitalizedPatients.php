<?php

namespace Tests\Feature\Hospitalization\Concerns;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\HospitalStay;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Un patient réellement admis : arrivée, consultation, demande
 * d'hospitalisation — l'admission automatique de l'ADR-113 fait le reste.
 */
trait AdmitsHospitalizedPatients
{
    private function admitted(User $doctor, string $lastName = 'Rakoto', string $firstName = 'Soa'): HospitalStay
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => $firstName,
            'last_name' => mb_strtoupper($lastName),
            'birth_date' => '1990-01-01',
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

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        return HospitalStay::query()->where('episode_id', $episode->id)->sole();
    }

    private function hospitalDoctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'diagnoses.view', 'diagnoses.create', 'medical_discharge.create',
            'hospitalization.request', 'hospitalization.view', 'hospitalization.update',
        ] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
