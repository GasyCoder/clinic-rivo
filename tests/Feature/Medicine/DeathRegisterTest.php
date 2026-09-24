<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\DeathRecord;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-107 — le registre des décès et son acte de constatation.
 *
 * Deux garanties opposées :
 *
 *   le décès prononcé  -> apparaît au registre, et son acte peut être signé
 *   aucun décès        -> aucun acte, jamais
 *
 * La seconde compte autant : signer la constatation d'un décès que personne
 * n'a prononcé attesterait un fait clinique inexistant.
 */
class DeathRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_passage_discharged_as_deceased_appears_in_the_register(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);

        $this->dischargeAsDeceased($doctor, $orientation);

        $episodes = $this->actingAs($doctor)->get('/deces')
            ->assertOk()
            ->viewData('page')['props']['episodes']['data'];

        $this->assertCount(1, $episodes);
        $this->assertSame($episode->uuid, $episodes[0]['uuid']);
        // L'acte manque : c'est un document que la famille n'a pas, et
        // l'écran doit pouvoir le nommer.
        $this->assertNull($episodes[0]['record']);
        $this->assertTrue($episodes[0]['can_record']);
    }

    /** Une sortie ordinaire n'a rien à faire dans ce registre. */
    public function test_an_ordinary_discharge_never_reaches_the_register(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => 'NORMAL',
            'final_diagnosis' => 'Angine',
            'patient_condition' => 'Stable',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();

        $this->assertEmpty($this->actingAs($doctor)->get('/deces')
            ->viewData('page')['props']['episodes']['data']);
    }

    /** Le médecin est conduit au registre : un décès a une suite propre. */
    public function test_pronouncing_a_death_redirects_to_the_register(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->deceasedPayload())
            ->assertRedirect('/deces');
    }

    public function test_the_certificate_records_what_the_doctor_constates(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);
        $this->dischargeAsDeceased($doctor, $orientation);

        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", [
            // Corrigé ici : le médecin qui constate signe ce qu'il écrit,
            // il ne contresigne pas la saisie d'un autre écran.
            'death_occurred_at' => now()->subHours(3)->format('Y-m-d H:i'),
            'death_place' => 'Salle de réanimation',
            'death_causes' => 'Arrêt cardio-respiratoire',
            'observations' => 'Famille informée.',
        ])->assertRedirect('/deces')->assertSessionHasNoErrors();

        $record = DeathRecord::query()->sole();

        $this->assertSame($episode->id, $record->episode_id);
        $this->assertSame('Salle de réanimation', $record->death_place);
        $this->assertSame($doctor->id, $record->constated_by);
        // L'heure de constatation appartient au serveur : elle atteste
        // quand l'acte a été signé, pas quand on a rempli le formulaire.
        $this->assertNotNull($record->constated_at);
    }

    /**
     * Le certificat suit la feuille de la clinique : filiation, CNI et lieu de
     * signature en plus, causes et observations en texte riche assaini.
     */
    public function test_the_certificate_follows_the_clinic_form_in_rich_text(): void
    {
        config(['rivo.site.name' => 'Ambondromamy']);
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);
        $episode->patient->update([
            'birth_place' => 'Mahajanga',
            'address' => 'Lot II A 12, Ambondromamy',
            'identity_document_number' => '401 011 022 033',
        ]);
        $this->dischargeAsDeceased($doctor, $orientation);

        // Le dossier connu pré-remplit la saisie.
        $this->actingAs($doctor)->get('/deces')->assertInertia(fn ($page) => $page
            ->where('episodes.data.0.patient.birth_place', 'Mahajanga')
            ->where('episodes.data.0.patient.identity_document_number', '401 011 022 033')
            ->where('defaultSignedPlace', 'Ambondromamy'));

        // Un éditeur vidé ne dit aucune cause.
        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload([
            'death_causes' => '<p><br></p>',
        ]))->assertSessionHasErrors('death_causes');

        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload([
            'birth_place' => 'Mahajanga I',
            'address' => 'Lot II A 12, Ambondromamy',
            'father_name' => 'RAKOTO Jean',
            'mother_name' => 'RASOA Marie',
            'identity_document_number' => '401 011 022 033',
            'identity_document_issued_on' => '2010-05-04',
            'identity_document_issued_place' => 'Mahajanga',
            'death_causes' => '<p><strong>Arrêt cardio-respiratoire</strong></p><script>alert(1)</script>',
            'observations' => '<p><br></p>',
            'signed_place' => '',
        ]))->assertSessionHasNoErrors();

        $record = DeathRecord::query()->sole();
        $this->assertSame('<p><strong>Arrêt cardio-respiratoire</strong></p>', $record->death_causes);
        $this->assertNull($record->observations);
        $this->assertSame('RAKOTO Jean', $record->father_name);
        $this->assertSame('2010-05-04', $record->identity_document_issued_on->toDateString());
        // Le lieu de signature par défaut est le site.
        $this->assertSame('Ambondromamy', $record->signed_place);
        // Le certificat fige ce qu'il atteste ; le dossier n'est pas modifié.
        $this->assertSame('Mahajanga I', $record->birth_place);
        $this->assertSame('Mahajanga', $episode->patient->fresh()->birth_place);

        $this->actingAs($doctor)->get("/deces/{$episode->uuid}/acte/impression")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Deaths/CertificatePrint')
                ->where('patient.birth_place', 'Mahajanga I')
                ->where('patient.address', 'Lot II A 12, Ambondromamy')
                ->where('record.mother_name', 'RASOA Marie')
                ->where('record.death_causes_html', '<p><strong>Arrêt cardio-respiratoire</strong></p>')
                ->where('record.observations_html', null)
                ->where('record.signed_place', 'Ambondromamy')
                ->where('record.constated_by', $doctor->name));
    }

    /** Un acte signé avant l'amendement se lit sur le dossier, sans rien inventer. */
    public function test_an_older_certificate_prints_from_the_patient_record(): void
    {
        config(['rivo.site.name' => 'Ambondromamy']);
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);
        $episode->patient->update(['birth_place' => 'Mahajanga', 'address' => 'Lot II A 12']);
        $this->dischargeAsDeceased($doctor, $orientation);
        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload())
            ->assertSessionHasNoErrors();

        DeathRecord::query()->update([
            'birth_place' => null, 'address' => null, 'signed_place' => null,
            'death_causes' => "Arrêt cardio-respiratoire\nConstaté au lit",
        ]);

        $this->actingAs($doctor)->get("/deces/{$episode->uuid}/acte/impression")
            ->assertInertia(fn ($page) => $page
                ->where('patient.birth_place', 'Mahajanga')
                ->where('patient.address', 'Lot II A 12')
                ->where('record.signed_place', 'Ambondromamy')
                ->where('record.death_causes_html', 'Arrêt cardio-respiratoire<br>Constaté au lit')
                ->where('record.father_name', null));
    }

    /** Un second acte serait un doublon d'état civil, jamais une correction. */
    public function test_a_passage_carries_only_one_certificate(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);
        $this->dischargeAsDeceased($doctor, $orientation);

        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload())
            ->assertSessionHasNoErrors();
        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload())
            ->assertSessionHasErrors('death_record');

        $this->assertSame(1, DeathRecord::query()->count());
    }

    /** L'acte ne prononce pas le décès : il ne peut pas le devancer. */
    public function test_no_certificate_without_a_death_pronounced(): void
    {
        $doctor = $this->doctor();
        [$episode] = $this->passage($doctor);

        $this->actingAs($doctor)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload())
            ->assertSessionHasErrors('death_record');

        $this->assertSame(0, DeathRecord::query()->count());
    }

    /**
     * Voir le registre et signer l'acte ne sont pas la même autorité : suivre
     * les passages concernés n'est pas établir un document médico-légal.
     */
    public function test_reading_the_register_does_not_grant_signing_it(): void
    {
        $reader = $this->doctor(['death_records.create']);
        [$episode, $orientation] = $this->passage($reader);
        $this->dischargeAsDeceased($this->doctor(), $orientation);

        $this->actingAs($reader)->get('/deces')->assertOk();
        $this->actingAs($reader)->post("/deces/{$episode->uuid}/acte", $this->certificatePayload())
            ->assertForbidden();
    }

    /**
     * « Guéri », « Suivre le traitement jusqu'au bout », « Contrôle dans
     * 7 jours » : des instructions sans destinataire, qui s'imprimeraient sur
     * le document remis à la famille. L'état, lui, n'est pas absent — le
     * type de sortie *est* la réponse, et le serveur la pose.
     */
    public function test_a_death_carries_no_instruction_for_a_living_patient(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->passage($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->deceasedPayload())
            ->assertSessionHasNoErrors();

        $discharge = $episode->fresh()->medicalDischarge;

        $this->assertSame('Décédé', $discharge->patient_condition);
        $this->assertNull($discharge->discharge_prescription);
        $this->assertNull($discharge->recommendations);
        $this->assertNull($discharge->follow_up_at);
    }

    /** Un payload forgé reçoit une erreur nommée, jamais un silence. */
    public function test_forging_those_fields_on_a_death_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            ...$this->deceasedPayload(),
            'discharge_prescription' => 'Amoxicilline 500 mg',
            'recommendations' => 'Repos',
            'follow_up_at' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors(['discharge_prescription', 'recommendations', 'follow_up_at']);
    }

    /** Une sortie ordinaire continue d'exiger l'état du patient (CDC §33.1). */
    public function test_an_ordinary_discharge_still_demands_the_patient_condition(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->passage($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => 'NORMAL',
            'final_diagnosis' => 'Angine',
            'patient_condition' => '',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('patient_condition');
    }

    private function dischargeAsDeceased(User $doctor, EpisodeOrientation $orientation): void
    {
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", $this->deceasedPayload())
            ->assertSessionHasNoErrors();
    }

    /**
     * Exactement ce que le formulaire envoie.
     *
     * Les champs retirés de l'écran restent dans l'objet du formulaire et
     * partent donc à vide : les omettre ici avait laissé passer une règle
     * qui échouait sur ce `null` précis, et le médecin lisait « doit être
     * une chaîne de caractères » sur un champ qu'on venait de lui retirer.
     *
     * @return array<string, mixed>
     */
    private function deceasedPayload(): array
    {
        return [
            'type' => 'DECEASED',
            'final_diagnosis' => 'Choc septique',
            'patient_condition' => '',
            'discharge_prescription' => '',
            'recommendations' => '',
            'follow_up_at' => '',
            'observations' => '',
            'transfer_destination' => '',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'death_occurred_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'death_place' => 'Salle de soins',
            'death_causes' => 'Choc septique réfractaire',
        ];
    }

    /** @return array<string, string> */
    /** @param array<string, mixed> $overrides */
    private function certificatePayload(array $overrides = []): array
    {
        return array_merge([
            'death_occurred_at' => now()->subHours(2)->format('Y-m-d H:i'),
            'death_place' => 'Salle de soins',
            'death_causes' => 'Choc septique réfractaire',
        ], $overrides);
    }

    /** @param array<int, string> $without */
    private function doctor(array $without = []): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view',
            'medical_discharge.create', 'death_records.view', 'death_records.create',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['role_id' => $role->id]);

        // Un DENY individuel reste prioritaire sur le socle du rôle (ADR-033) :
        // c'est le chemin honnête pour retirer un droit à un seul compte.
        foreach ($without as $name) {
            DB::table('user_permissions')->insert([
                'user_id' => $user->id,
                'permission_id' => Permission::query()->where('name', $name)->value('id'),
                'effect' => 'deny',
                'source' => 'MANUAL',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function passage(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Solofo',
            'last_name' => 'Rakotoarisoa',
            'birth_date' => '1952-08-03',
            'sex' => 'M',
        ]);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $item = CatalogItem::query()->create([
            'code' => 'ITEM-'.uniqid(),
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
