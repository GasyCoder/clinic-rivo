<?php

namespace Tests\Feature\Medicine;

use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CreateConsultationAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Actions\Medicine\RecordDiagnosisAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\MedicineForm;
use App\Enums\PrescriptionStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeEpisode(EpisodeAdministrativeStatus $status = EpisodeAdministrativeStatus::Oriented): Episode
    {
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => $status,
            'started_at' => now(),
        ]);
    }

    private function stockedMedicine(User $actor, string $name): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'TEST-MED-'.(CatalogItem::query()->count() + 1),
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
            'generic_name' => $name,
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'TEST-LOT-'.(MedicineLot::query()->count() + 1),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 50,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $medicine->load('catalogItem');
    }

    public function test_create_consultation_action_moves_episode_to_in_care(): void
    {
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $episode = $this->makeEpisode();

        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, [
            'reason' => 'Douleur abdominale',
        ]);

        $this->assertSame($doctor->id, $consultation->doctor_id);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_a_second_consultation_does_not_error_once_already_in_care(): void
    {
        $episode = $this->makeEpisode(EpisodeAdministrativeStatus::InCare);
        $this->actingAs(User::factory()->create());

        $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Suivi']);

        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_record_diagnosis_action_records_who_captured_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $episode = $this->makeEpisode();
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);

        $diagnosis = $this->app->make(RecordDiagnosisAction::class)
            ->execute($consultation, DiagnosisType::Final, 'Appendicite aiguë');

        $this->assertSame('Appendicite aiguë', $diagnosis->description);
        $this->assertSame($user->id, $diagnosis->recorded_by);
    }

    public function test_create_prescription_action_creates_lines(): void
    {
        $episode = $this->makeEpisode();
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);
        $paracetamol = $this->stockedMedicine($doctor, 'Paracétamol');
        $amoxicilline = $this->stockedMedicine($doctor, 'Amoxicilline');

        $prescription = $this->app->make(CreatePrescriptionAction::class)->execute($consultation, [
            ['medicine_uuid' => $paracetamol->catalogItem->uuid, 'quantity' => 3, 'dosage' => '1g', 'frequency' => '3x/jour'],
            ['medicine_uuid' => $amoxicilline->catalogItem->uuid, 'quantity' => 2, 'dosage' => '500mg'],
        ], $doctor);

        $this->assertSame(PrescriptionStatus::Active, $prescription->status);
        $this->assertSame(2, $prescription->lines()->count());
    }

    public function test_cancel_prescription_action_cancels_with_a_reason(): void
    {
        $episode = $this->makeEpisode();
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);
        $medicine = $this->stockedMedicine($doctor, 'Paracétamol');
        $prescription = $this->app->make(CreatePrescriptionAction::class)
            ->execute($consultation, [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 1,
            ]], $doctor);

        $cancelled = $this->app->make(CancelPrescriptionAction::class)
            ->execute($prescription, 'Erreur de saisie', $doctor);

        $this->assertSame(PrescriptionStatus::Cancelled, $cancelled->status);
    }
}
