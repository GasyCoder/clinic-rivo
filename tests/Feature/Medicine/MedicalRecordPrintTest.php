<?php

namespace Tests\Feature\Medicine;

use App\Models\CareRecord;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientAntecedent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-116 — le « DOSSIER MÉDICAL » de la clinique.
 *
 * Chaque case reprend ce qui est déjà consigné ailleurs : rien n'est
 * ressaisi, et les sections plus sensibles restent gouvernées par leur
 * propre permission — la feuille imprimée n'est pas un moyen de contourner
 * `vitals.view` ou `patients.medical_history.view` (ADR-054).
 */
class MedicalRecordPrintTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names, string $roleCode = 'MEDICINE'): User
    {
        $role = Role::query()->create(['code' => $roleCode.'-'.uniqid(), 'name' => $roleCode]);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_the_sheet_reads_from_what_is_already_recorded(): void
    {
        $doctor = $this->userWithPermissions([
            'patients.view', 'vitals.view', 'patients.medical_history.view',
            // Le diagnostic, les traitements et l'hospitalisation sont gardés par leur propre droit.
            'diagnoses.view', 'medical_record.view', 'hospitalization.view',
        ]);

        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Nirina',
            'last_name' => 'Rasata',
            'birth_date' => '1978-06-02',
            'sex' => 'F',
            'marital_status' => 'MARRIED',
            'children_count' => 2,
            'profession' => 'Enseignante',
            'address' => 'Lot II B 4, Antsirabe',
            'phone' => '032 00 000 00',
        ]);

        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'started_at' => now()->subHours(4),
            'created_by' => $doctor->id,
        ]);

        CareRecord::query()->create([
            'episode_id' => $episode->id,
            'blood_group' => 'O+',
            'height_cm' => 165,
            'weight_kg' => 60,
            'bmi' => 22.0,
            'smoker' => false,
            'transmission_reason' => 'Douleur abdominale',
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        PatientAllergy::query()->create([
            'patient_id' => $patient->id,
            'substance' => 'Pénicilline',
            'severity' => 'SEVERE',
            'recorded_by' => $doctor->id,
        ]);

        PatientAntecedent::query()->create([
            'patient_id' => $patient->id,
            'type' => 'FAMILIAL',
            'description' => 'Diabète (père)',
            'recorded_by' => $doctor->id,
        ]);

        $consultation = Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'reason' => '<p>Douleur abdominale.</p>',
            'consulted_at' => now()->subHour(),
        ]);

        Diagnosis::query()->create([
            'consultation_id' => $consultation->id,
            'type' => 'FINAL',
            'description' => 'Gastrite aiguë',
            'is_manual' => true,
            'recorded_by' => $doctor->id,
        ]);

        $response = $this->actingAs($doctor)->get("/passages/{$episode->uuid}/dossier-medical")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Medicine/MedicalRecordPrint'));

        $props = $response->viewData('page')['props'];

        $this->assertSame('Rasata Nirina', $props['patient']['name']);
        $this->assertSame('Enseignante', $props['patient']['profession']);
        $this->assertTrue($props['vitals_visible']);
        $this->assertSame('O+', $props['vitals']['blood_group']);
        $this->assertTrue($props['history_visible']);
        $this->assertSame(['Pénicilline'], $props['allergies']);
        $this->assertSame(['Diabète (père)'], $props['familial_antecedents']);
        $this->assertSame('Gastrite aiguë', $props['diagnosis']);
    }

    /** Une permission manquante se nomme ; elle ne se lit jamais comme « rien à signaler ». */
    public function test_restricted_sections_are_hidden_without_their_permission(): void
    {
        $limited = $this->userWithPermissions(['patients.view']);

        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Tiana',
            'last_name' => 'Randria',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);

        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'started_at' => now(),
            'created_by' => $limited->id,
        ]);

        CareRecord::query()->create(['episode_id' => $episode->id, 'blood_group' => 'AB-', 'created_by' => $limited->id, 'updated_by' => $limited->id]);
        PatientAllergy::query()->create(['patient_id' => $patient->id, 'substance' => 'Iode', 'recorded_by' => $limited->id]);

        $props = $this->actingAs($limited)->get("/passages/{$episode->uuid}/dossier-medical")
            ->viewData('page')['props'];

        $this->assertFalse($props['vitals_visible']);
        $this->assertNull($props['vitals']);
        $this->assertFalse($props['history_visible']);
        $this->assertSame([], $props['allergies']);
    }

    /**
     * Une feuille imprimée n'est pas un moyen de contourner un droit (ADR-116, amendement du 2026-09-20).
     *
     * Le défaut constaté : un compte de Réception, qui n'a que `patients.view`, lisait ici le diagnostic
     * et les traitements déclarés — que la page « Détail du passage » lui refuse (ADR-054).
     */
    public function test_the_diagnosis_and_the_treatments_are_never_read_without_their_own_permission(): void
    {
        $reception = $this->userWithPermissions(['patients.view'], 'RECEPTION-TEST');
        $doctor = $this->userWithPermissions(['patients.view', 'diagnoses.view', 'medical_record.view', 'hospitalization.view'], 'MEDECIN-TEST');

        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Hanta', 'last_name' => 'Ravelo', 'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $episode = Episode::create([
            'patient_id' => $patient->id, 'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN', 'started_at' => now(), 'created_by' => $doctor->id,
        ]);
        $consultation = Consultation::create([
            'episode_id' => $episode->id, 'doctor_id' => $doctor->id, 'reason' => 'Motif', 'consulted_at' => now(),
        ]);
        Diagnosis::create([
            'consultation_id' => $consultation->id, 'type' => 'FINAL',
            'description' => 'Sérologie positive', 'is_manual' => true, 'recorded_by' => $doctor->id,
        ]);
        $consultation->currentTreatments()->create(['medication_name' => 'Trithérapie', 'position' => 1]);

        $hidden = $this->actingAs($reception)->get("/passages/{$episode->uuid}/dossier-medical")
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($hidden['diagnosis_visible']);
        $this->assertNull($hidden['diagnosis']);
        $this->assertFalse($hidden['record_visible']);
        $this->assertSame([], collect($hidden['current_treatments'])->all());
        $this->assertFalse($hidden['stay_visible']);
        $this->assertNull($hidden['hospitalization']);
        // Rien ne doit rester dans la charge utile : la feuille est sérialisée entière au navigateur.
        $this->assertStringNotContainsString('Sérologie positive', json_encode($hidden));
        $this->assertStringNotContainsString('Trithérapie', json_encode($hidden));

        // Le médecin, lui, lit la feuille complète.
        $full = $this->actingAs($doctor)->get("/passages/{$episode->uuid}/dossier-medical")
            ->viewData('page')['props'];

        $this->assertTrue($full['diagnosis_visible']);
        $this->assertSame('Sérologie positive', $full['diagnosis']);
        $this->assertSame(['Trithérapie'], collect($full['current_treatments'])->all());
    }

    public function test_the_sheet_requires_the_same_permission_as_the_passage_detail_page(): void
    {
        $noAccess = User::factory()->create(['role_id' => Role::query()->create(['code' => 'NOROLE', 'name' => 'NOROLE'])->id]);

        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Fara',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);

        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'started_at' => now(),
            'created_by' => $noAccess->id,
        ]);

        $this->actingAs($noAccess)->get("/passages/{$episode->uuid}/dossier-medical")->assertForbidden();
    }
}
