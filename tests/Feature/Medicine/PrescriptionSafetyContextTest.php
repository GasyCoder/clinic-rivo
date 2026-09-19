<?php

namespace Tests\Feature\Medicine;

use App\Models\CareRecord;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Medicine\Concerns\BuildsClinicalSuggestionFixtures;
use Tests\TestCase;

/**
 * ADR-128 — l'écran d'ordonnance reçoit ce qu'il faut pour relire une ligne :
 * l'âge, le poids relevé aux Soins et l'allergie que chaque médicament recoupe.
 *
 * Le serveur ne juge aucune dose : il n'en connaît aucune limite.
 */
class PrescriptionSafetyContextTest extends TestCase
{
    use BuildsClinicalSuggestionFixtures, RefreshDatabase;

    public function test_the_ordonnance_page_serves_age_weight_and_allergy_conflicts(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: now()->subYears(4)->toDateString());
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $this->medicine($doctor, 'Paracétamol 500 mg', 'Paracétamol');

        $consultation->episode->patient->allergies()->create(['substance' => 'amoxicilline', 'recorded_by' => $doctor->id]);
        CareRecord::query()->where('episode_id', $consultation->episode_id)->delete();
        $this->careRecordWithWeight($consultation, $doctor, 16.5);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('prescription_safety.age', 4)
                ->where('prescription_safety.weight_kg', 16.5)
                ->where('prescription_safety.allergy_conflicts', [$amoxicillin->catalogItem->uuid => 'amoxicilline']));
    }

    public function test_a_missing_weight_is_null_never_zero(): void
    {
        $doctor = $this->doctor();
        [$orientation] = $this->consultation($doctor, birthDate: now()->subYears(4)->toDateString());

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertInertia(fn ($page) => $page->where('prescription_safety.weight_kg', null));
    }

    public function test_nothing_is_served_without_the_right_to_prescribe(): void
    {
        $reader = $this->doctor(manage: false);
        [$orientation] = $this->consultation($reader, birthDate: now()->subYears(4)->toDateString());
        $reader->role->permissions()->detach(
            Permission::query()->where('name', 'prescriptions.create')->value('id'),
        );

        $this->actingAs($reader->fresh())
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('prescription_safety', null));
    }

    private function careRecordWithWeight($consultation, $doctor, float $weight): void
    {
        CareRecord::query()->create([
            'episode_id' => $consultation->episode_id,
            'weight_kg' => $weight,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
    }
}
