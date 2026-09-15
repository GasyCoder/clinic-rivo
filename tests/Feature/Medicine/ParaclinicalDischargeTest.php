<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Diagnosis;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MedicalDischarge;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-094 — un passage venu seulement pour un examen se clôt sans diagnostic.
 *
 * Ce que ces tests protègent tient en deux phrases opposées :
 *
 *   ECG / écho / analyse seuls  -> le diagnostic est facultatif
 *   toute vraie consultation    -> il reste obligatoire (CDC §33.1)
 *
 * La seconde compte autant que la première. Un assouplissement qui
 * déborderait laisserait clore un dossier médical sans aucune conclusion.
 */
class ParaclinicalDischargeTest extends TestCase
{
    use RefreshDatabase;

    /** Le cas du client : Mme R. venue pour un ECG, dont le résultat n'est pas revenu. */
    public function test_a_paraclinical_only_passage_is_discharged_without_a_diagnosis(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Imaging, 'Électrocardiogramme (ECG)');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->dischargePayload())
            ->assertSessionHasNoErrors();

        $discharge = MedicalDischarge::query()->sole();

        $this->assertNull($discharge->final_diagnosis, 'Aucun diagnostic ne doit être fabriqué.');
        $this->assertSame('Stable', $discharge->patient_condition);
    }

    /** Une absence reste une absence : pas de diagnostic vide en base. */
    public function test_it_never_fabricates_an_empty_diagnosis(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Laboratory, 'Numération formule sanguine');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->dischargePayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Diagnosis::query()->count());
    }

    /** Le médecin garde le droit d'en poser un : facultatif n'est pas interdit. */
    public function test_a_diagnosis_offered_on_a_paraclinical_passage_is_still_recorded(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Imaging, 'Échographie abdominale');

        $this->actingAs($doctor)
            ->post(
                "/medicine/orientations/{$orientation->uuid}/discharge",
                $this->dischargePayload(['final_diagnosis' => 'Stéatose hépatique']),
            )
            ->assertSessionHasNoErrors();

        $this->assertSame('Stéatose hépatique', MedicalDischarge::query()->sole()->final_diagnosis);
        $this->assertSame('Stéatose hépatique', Diagnosis::query()->sole()->description);
    }

    /** La garantie inverse — celle qu'un assouplissement trop large casserait. */
    public function test_an_ordinary_consultation_still_demands_a_final_diagnosis(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Medicine, 'Consultation générale');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->dischargePayload())
            ->assertSessionHasErrors('final_diagnosis');

        $this->assertSame(0, MedicalDischarge::query()->count());
    }

    /** La clôture et la sortie doivent dire la même chose, sinon l'une piège l'autre. */
    public function test_the_closure_no_longer_lists_the_diagnosis_for_a_paraclinical_passage(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Imaging, 'Électrocardiogramme (ECG)');

        $blockers = $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/cloture")
            ->assertOk()
            ->viewData('page')['props']['consultation']['closure_blockers'];

        $this->assertEmpty(array_filter($blockers, fn (array $b): bool => str_contains($b['message'], 'Diagnostic')));
    }

    public function test_the_closure_still_demands_one_for_an_ordinary_consultation(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor, CatalogModule::Medicine, 'Consultation générale');

        $blockers = $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/cloture")
            ->assertOk()
            ->viewData('page')['props']['consultation']['closure_blockers'];

        $this->assertNotEmpty(array_filter($blockers, fn (array $b): bool => str_contains($b['message'], 'Diagnostic')));
    }

    /**
     * Le but réel du client : le passage ne reste pas éternellement en
     * consultation. Sans diagnostic, il va jusqu'à la Réception.
     */
    public function test_the_passage_reaches_the_reception_without_any_diagnosis(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor, CatalogModule::Imaging, 'Électrocardiogramme (ECG)');

        // Les étapes restantes suivent leur cours normal : ADR-094 ne lève
        // que le diagnostic, jamais la résolution des étapes (ADR-076).
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/steps", [
            'step' => 'dossier', 'intent' => 'COMPLETE',
        ])->assertSessionHasNoErrors();

        foreach (['paraclinique', 'ordonnance'] as $step) {
            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => $step, 'intent' => 'SKIP',
            ])->assertSessionHasNoErrors();
        }

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->dischargePayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasNoErrors();

        $this->assertSame(
            EpisodeAdministrativeStatus::PendingSettlement,
            $episode->fresh()->administrative_status,
        );
        $this->assertSame(0, Diagnosis::query()->count());
    }

    /** @return array<string, string> */
    private function dischargePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'NORMAL',
            'final_diagnosis' => '',
            'patient_condition' => 'Stable',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view',
            'medical_discharge.create',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /**
     * Un passage dont la seule prestation appartient au module donné.
     *
     * `IMAGING`/`LABORATORY` produisent un passage paraclinique seul ;
     * `MEDICINE` une consultation ordinaire. C'est le seul paramètre qui
     * change entre les deux moitiés de ce fichier.
     *
     * @return array{0: Episode, 1: EpisodeOrientation}
     */
    private function passage(User $doctor, CatalogModule $module, string $name): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Eliana',
            'last_name' => 'Ranavalona',
            'birth_date' => '1985-04-12',
            'sex' => 'F',
        ]);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $item = CatalogItem::query()->create([
            'code' => 'ITEM-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'examen',
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

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
