<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le médecin corrige une constante manifestement fausse (ADR-093).
 *
 * La garantie sous test n'est pas « le médecin peut écrire » — c'est que ce
 * qu'il peut écrire s'arrête exactement aux constantes, et que la valeur
 * qu'il remplace reste lisible à l'audit.
 */
class CareVitalsCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_corrects_an_impossible_temperature(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $record = $this->careRecord($episode, $doctor, ['temperature_celsius' => 32.0, 'heart_rate' => 56]);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'temperature_celsius' => 36.2,
                'heart_rate' => 56,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('36.20', $record->fresh()->temperature_celsius);
    }

    /** La valeur remplacée n'est pas perdue : c'est ce qui rend l'écrasement acceptable. */
    public function test_the_replaced_value_stays_readable_in_the_audit_trail(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $this->careRecord($episode, $doctor, ['temperature_celsius' => 32.0]);

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['temperature_celsius' => 36.2])
            ->assertSessionHasNoErrors();

        $entry = AuditLog::query()
            ->where('entity_type', (new CareRecord)->getMorphClass())
            ->where('action', 'update')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'Une correction de constante doit produire une ligne d’audit.');
        $this->assertSame($doctor->id, $entry->user_id);
        // L'audit porte la valeur brute côté « new » et la valeur stockée
        // côté « old » : on compare donc les nombres, pas leur formatage.
        $this->assertSame(32.0, (float) data_get($entry->old_values, 'temperature_celsius'));
        $this->assertSame(36.2, (float) data_get($entry->new_values, 'temperature_celsius'));
    }

    /** L'IMC est recalculé par le serveur, jamais repris du navigateur. */
    public function test_the_bmi_is_recomputed_and_never_accepted_from_the_browser(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $record = $this->careRecord($episode, $doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'height_cm' => 170,
                'weight_kg' => 65,
                'bmi' => 99,
            ])
            ->assertSessionHasErrors('bmi');

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['height_cm' => 170, 'weight_kg' => 65])
            ->assertSessionHasNoErrors();

        $this->assertSame('22.49', $record->fresh()->bmi);
    }

    /**
     * Le périmètre, et c'est le cœur de cette ADR : un acte créerait un
     * BillableItem et un consommable une sortie de stock Pharmacie, depuis
     * un écran de consultation.
     */
    public function test_it_refuses_everything_that_is_not_a_vital_sign(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $this->careRecord($episode, $doctor);

        foreach ([
            'procedures' => [['catalog_item_uuid' => fake()->uuid(), 'quantity' => 1]],
            'consumables' => [['medicine_uuid' => fake()->uuid(), 'quantity' => 1]],
            'new_allergies' => [['substance' => 'Pénicilline']],
            'transmission_reason' => 'Transmission inventée par le médecin',
            'diagnostic_note' => 'Note de transmission',
        ] as $field => $value) {
            $this->actingAs($doctor)
                ->put($this->url($orientation), [$field => $value])
                ->assertSessionHasErrors($field);
        }
    }

    /** Les bornes cliniques sont celles de la fiche Soins, pas des bornes propres à Médecine. */
    public function test_it_applies_the_same_clinical_bounds_as_the_nursing_worksheet(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $this->careRecord($episode, $doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['temperature_celsius' => 12])
            ->assertSessionHasErrors('temperature_celsius');

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'blood_pressure_systolic' => 80,
                'blood_pressure_diastolic' => 120,
            ])
            ->assertSessionHasErrors('blood_pressure_systolic');
    }

    /** Sans `vitals.update`, la correction est refusée côté serveur. */
    public function test_a_doctor_without_the_permission_cannot_correct(): void
    {
        $reader = $this->doctor([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
        ]);
        [$episode, $orientation] = $this->medicineConsultation($reader);
        $record = $this->careRecord($episode, $reader, ['temperature_celsius' => 32.0]);

        $this->actingAs($reader)
            ->put($this->url($orientation), ['temperature_celsius' => 36.2])
            ->assertForbidden();

        $this->assertSame('32.00', $record->fresh()->temperature_celsius);
    }

    /** Un passage clos par la sortie administrative ne se corrige plus (ADR-090). */
    public function test_a_closed_episode_refuses_the_correction(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $record = $this->careRecord($episode, $doctor, ['temperature_celsius' => 32.0]);
        $episode->forceFill(['status' => EpisodeStatus::Closed])->save();

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['temperature_celsius' => 36.2])
            ->assertSessionHasErrors('care_record');

        $this->assertSame('32.00', $record->fresh()->temperature_celsius);
    }

    /** Médecine corrige une mesure ; elle n'en signe pas une que personne n'a prise. */
    public function test_it_refuses_to_create_a_worksheet_that_never_existed(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['temperature_celsius' => 36.2])
            ->assertSessionHasErrors('care_record');

        $this->assertDatabaseMissing('care_records', ['episode_id' => $episode->id]);
    }

    /** Le drapeau doit atteindre la page, sinon le formulaire ne s'affiche jamais. */
    public function test_the_page_tells_the_doctor_whether_the_correction_is_open(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $this->careRecord($episode, $doctor, ['temperature_celsius' => 32.0]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('care_record.can_correct_vitals', true));

        $reader = $this->doctor([
            'medical_record.view', 'consultations.view', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
        ]);
        [$otherEpisode, $otherOrientation] = $this->medicineConsultation($reader);
        $this->careRecord($otherEpisode, $reader);

        $this->actingAs($reader)
            ->get("/medicine/orientations/{$otherOrientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('care_record.can_correct_vitals', false));
    }

    private function url(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/constantes";
    }

    /** @param array<string, mixed> $vitals */
    private function careRecord(Episode $episode, User $nurse, array $vitals = []): CareRecord
    {
        return CareRecord::query()->create([
            'episode_id' => $episode->getKey(),
            'created_by' => $nurse->getKey(),
            'updated_by' => $nurse->getKey(),
            ...$vitals,
        ]);
    }

    /** @param array<int, string>|null $permissions */
    private function doctor(?array $permissions = null): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $permissions === null ? 'MEDICINE' : 'MEDICINE_READER'],
            ['name' => $permissions === null ? 'Médecine' : 'Médecine (lecture)'],
        );

        foreach ($permissions ?? [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view', 'vitals.update',
            'diagnoses.view', 'prescriptions.view',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function medicineConsultation(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Florent',
            'last_name' => 'Bezara',
            'birth_date' => '1981-01-01',
            'sex' => 'M',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation de médecine générale',
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
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $doctor);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
