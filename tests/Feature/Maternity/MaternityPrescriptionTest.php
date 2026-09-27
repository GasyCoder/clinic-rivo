<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PrescriptionStatus;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-205 — la sage-femme prescrit depuis le dossier Maternité.
 *
 *   même ordonnance qu'en consultation   réservation FEFO, demande de délivrance à la Pharmacie
 *   rattachée au dossier                 maternity_record_id, aucune consultation ouverte
 *   mêmes droits                         prescriptions.* — recommandés au profil, jamais d'office
 *   délivrance ordinaire                 règlement à la Caisse d'abord (ADR-049), pas « au service »
 */
class MaternityPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_midwife_profile_recommends_prescribing_without_granting_it_to_the_nurse_role(): void
    {
        $recommended = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail()
            ->recommendedPermissions->pluck('name');

        foreach (['prescriptions.view', 'prescriptions.create', 'prescriptions.cancel', 'medicines.view', 'stock.availability.view'] as $permission) {
            $this->assertTrue($recommended->contains($permission), "{$permission} recommandé au profil sage-femme");
        }

        // Le socle NURSE n'en reçoit aucun : une infirmière ne prescrit pas (ADR-072).
        $nurse = User::factory()->create(['role_id' => Role::query()->where('code', 'NURSE')->value('id')]);
        $this->assertFalse($nurse->can('prescriptions.create'));
    }

    public function test_a_prescription_belongs_to_the_maternity_record_and_reserves_stock(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->linkedPrenatal($midwife);
        [$medicine, $lot] = $this->stockedMedicine($midwife);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances", [
            'lines' => [
                ['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 30, 'dosage' => '1 cp', 'frequency' => '1 fois/jour', 'duration' => '30 jours'],
                ['manual' => true, 'medication_name' => 'Acide folique 5 mg', 'quantity' => 1, 'dosage' => '5 mg', 'frequency' => '1 fois/jour', 'duration' => '3 mois'],
            ],
        ])->assertSessionHasNoErrors();

        $prescription = Prescription::query()->sole();
        $record = $episode->fresh()->maternityRecord;
        $this->assertSame($record->id, $prescription->maternity_record_id);
        $this->assertSame($episode->id, $prescription->episode_id);
        $this->assertNull($prescription->consultation_id, 'Aucune consultation Médecine n’est ouverte pour prescrire.');
        $this->assertNull($prescription->hospital_stay_id);
        $this->assertSame(0, $episode->consultations()->count());

        $this->assertSame(30, (int) MedicineStockReservation::query()
            ->where('medicine_lot_id', $lot->id)
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('remaining_quantity'));

        // La Pharmacie la reçoit ; la délivrance attend le règlement, comme en consultation.
        $dispense = $prescription->pharmacyDispense()->sole();
        $this->assertFalse($dispense->isWardDispense());

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('capabilities.can_prescribe', true)
                ->has('prescriptions', 1)
                ->where('prescriptions.0.lines.1.is_manual', true)
                ->where('prescriptions.0.can_cancel', true)
                ->where('prescriptions.0.print_url', "/maternity/orientations/{$orientation->uuid}/ordonnances/{$prescription->uuid}/impression")
                ->has('prescriptionOptions.medicines', 1));

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}/ordonnances/{$prescription->uuid}/impression")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Medicine/PrescriptionPrint')
                ->where('prescription.uuid', $prescription->uuid)
                // Une sage-femme signe sous son titre, jamais sous « Dr ».
                ->where('prescription.prescriber_title', 'Sage-femme')
                ->where('backHref', "/maternity/orientations/{$orientation->uuid}#ordonnance"));
    }

    public function test_a_prescription_is_withdrawn_with_a_reason_and_releases_the_stock(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->linkedPrenatal($midwife);
        [$medicine] = $this->stockedMedicine($midwife);
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances", [
            'lines' => [['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 10, 'dosage' => '1 cp', 'frequency' => '1 fois/jour', 'duration' => '10 jours']],
        ])->assertSessionHasNoErrors();
        $prescription = Prescription::query()->sole();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances/{$prescription->uuid}/annuler", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances/{$prescription->uuid}/annuler", ['reason' => 'Mauvais dosage'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PrescriptionStatus::Cancelled, $prescription->fresh()->status);
        $this->assertSame(0, (int) MedicineStockReservation::query()->where('status', MedicineStockReservationStatus::Reserved->value)->sum('remaining_quantity'));
    }

    public function test_prescribing_needs_the_prescription_rights_not_only_maternity(): void
    {
        $midwife = $this->midwife(prescribing: false);
        [, $orientation] = $this->linkedPrenatal($midwife);
        [$medicine] = $this->stockedMedicine($midwife);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances", [
            'lines' => [['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1, 'dosage' => '1 cp']],
        ])->assertForbidden();
        $this->assertSame(0, Prescription::query()->count());

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('capabilities.can_prescribe', false)
                ->where('prescriptions', null)
                ->where('prescriptionOptions.medicines', []));
    }

    public function test_a_finished_record_receives_no_prescription_and_another_record_is_not_reachable(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->linkedPrenatal($midwife);
        [, $other] = $this->linkedPrenatal($midwife);
        [$medicine] = $this->stockedMedicine($midwife);
        $line = ['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1, 'dosage' => '1 cp', 'frequency' => '1 fois/jour', 'duration' => '1 jours'];

        $this->actingAs($midwife)->post("/maternity/orientations/{$other->uuid}/ordonnances", ['lines' => [$line]])->assertSessionHasNoErrors();
        $foreign = Prescription::query()->sole();

        // Une ordonnance d'un autre dossier ne se retire ni ne s'imprime par celui-ci.
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances/{$foreign->uuid}/annuler", ['reason' => 'Erreur de dossier'])
            ->assertForbidden();
        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}/ordonnances/{$foreign->uuid}/impression")->assertNotFound();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/ordonnances", ['lines' => [$line]])->assertForbidden();
        $this->assertSame(1, Prescription::query()->count());
    }

    /** @return array{0: \App\Models\Episode, 1: EpisodeOrientation} */
    private function linkedPrenatal(User $midwife): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Marie', 'last_name' => 'Rakoto', 'birth_date' => '1994-06-10', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept")->assertRedirect();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'PRENATAL'])
            ->assertSessionHasNoErrors();
        $this->actingAs($midwife)->put("/maternity/orientations/{$orientation->uuid}/record", [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => now()->subWeeks(20)->toDateString()],
        ])->assertSessionHasNoErrors();

        return [$episode->fresh(), $orientation->fresh(['episode'])];
    }

    private function midwife(bool $prescribing = true): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);
        $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions
            ->reject(fn (Permission $permission) => ! $prescribing && str_starts_with($permission->name, 'prescriptions.'))
            ->mapWithKeys(fn (Permission $permission) => [$permission->id => ['effect' => 'allow']])
            ->all());

        return $user->fresh(['role', 'professionalProfile']);
    }

    /** @return array{0: Medicine, 1: MedicineLot} */
    private function stockedMedicine(User $actor): array
    {
        $item = CatalogItem::query()->create([
            'code' => 'PH-FER-'.fake()->unique()->numerify('###'), 'name' => 'Fer + acide folique', 'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy, 'unit' => 'comprimé', 'billable' => true, 'stockable' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id, 'generic_name' => 'Fer', 'form' => MedicineForm::Tablet,
            'strength' => '60 mg', 'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $lot = MedicineLot::query()->create([
            'medicine_id' => $medicine->id, 'lot_number' => 'LOT-'.$item->id, 'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(), 'quantity_on_hand' => 100, 'active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        return [$medicine->load('catalogItem'), $lot];
    }
}
