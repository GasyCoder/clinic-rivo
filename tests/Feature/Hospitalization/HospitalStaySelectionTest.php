<?php

namespace Tests\Feature\Hospitalization;

use App\Enums\HospitalStayStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Hospitalization\Concerns\AdmitsHospitalizedPatients;
use Tests\TestCase;

/**
 * ADR-165 — les patients hospitalisés cochés s'impriment ou s'exportent
 * ensemble : fiches de régime, dossiers médicaux, tour de salle, Excel.
 *
 * Tout est en lecture ; chaque feuille garde les droits de la feuille seule, et
 * l'export, qui emporte des données personnelles, exige son propre droit.
 */
class HospitalStaySelectionTest extends TestCase
{
    use AdmitsHospitalizedPatients, RefreshDatabase;

    public function test_the_list_tells_which_bulk_actions_this_account_may_launch(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);

        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.can_print_medical_records', true)
                ->where('capabilities.can_export', false)
                ->where('bulkLimit', 50)
                ->where('stays.data.0.episode_uuid', $stay->episode->uuid));

        $this->grant($doctor, 'hospitalization.export');

        $this->actingAs($doctor->fresh())->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page->where('capabilities.can_export', true));
    }

    public function test_diet_sheets_are_printed_together_one_per_stay_in_bed_order(): void
    {
        $doctor = $this->hospitalDoctor();
        $second = $this->admitted($doctor, 'Rabe', 'Hery');
        $first = $this->admitted($doctor, 'Rakoto', 'Soa');
        $second->update(['service' => 'Médecine', 'room_bed' => 'Chambre 2']);
        $first->update(['service' => 'Médecine', 'room_bed' => 'Chambre 1']);

        $this->actingAs($doctor)->get("/hospitalisation/selection/regimes?uuids[]={$second->uuid}&uuids[]={$first->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hospitalization/DietSheetsPrint')
                ->has('sheets', 2)
                // Classées comme on fait le tour des lits, pas dans l'ordre coché.
                ->where('sheets.0.uuid', $first->uuid)
                ->where('sheets.0.patient.name', 'RAKOTO Soa')
                ->where('sheets.0.request.reason', 'Déshydratation sévère')
                ->where('sheets.1.uuid', $second->uuid));
    }

    public function test_the_single_diet_sheet_reads_the_same_projection(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);

        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}/regime/impression")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hospitalization/DietSheetPrint')
                ->where('stay.uuid', $stay->uuid)
                ->where('stay.patient.name', 'RAKOTO Soa')
                ->where('stay.smoker', null)
                ->has('stay.diet_entries', 0));
    }

    public function test_medical_records_are_printed_together_and_keep_their_guards(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);

        $this->actingAs($doctor)->get("/hospitalisation/selection/dossiers-medicaux?uuids[]={$stay->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hospitalization/MedicalRecordsPrint')
                ->has('records', 1)
                ->where('records.0.back.href', '/hospitalisation')
                ->where('records.0.dossiers', null)
                // Sans vitals.view, les constantes ne sont pas servies — comme sur la feuille seule.
                ->where('records.0.vitals_visible', false)
                ->where('records.0.vitals', null));
    }

    public function test_medical_records_need_the_patient_record_right(): void
    {
        $viewer = $this->userWith(['hospitalization.view']);
        $stay = $this->admitted($this->hospitalDoctor());

        $this->actingAs($viewer)->get("/hospitalisation/selection/dossiers-medicaux?uuids[]={$stay->uuid}")->assertForbidden();
        $this->actingAs($viewer)->get("/hospitalisation/selection/regimes?uuids[]={$stay->uuid}")->assertOk();
    }

    public function test_the_ward_round_serves_the_latest_reading_only_with_the_vitals_right(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        $this->grant($doctor, 'vitals.create');
        $doctor = $doctor->fresh();

        foreach ([['temperature_celsius' => '39.4', 'heart_rate' => 118], ['temperature_celsius' => '37.2', 'heart_rate' => 88]] as $values) {
            $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", $values)->assertSessionHasNoErrors();
            $this->travel(5)->minutes();
        }

        // Sans vitals.view : la colonne n'existe pas, et rien n'est servi.
        $this->actingAs($doctor)->get("/hospitalisation/selection/tour-de-salle?uuids[]={$stay->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hospitalization/WardRoundPrint')
                ->where('vitals_visible', false)
                ->where('stays.0.latest_reading', null)
                ->where('stays.0.reason', 'Déshydratation sévère'));

        $this->grant($doctor, 'vitals.view');

        $this->actingAs($doctor->fresh())->get("/hospitalisation/selection/tour-de-salle?uuids[]={$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('vitals_visible', true)
                // Le dernier relevé, classé par les mêmes repères que l'onglet Surveillance.
                ->where('stays.0.latest_reading.heart_rate', 88)
                ->where('stays.0.latest_reading.alerts', []));
    }

    public function test_the_export_needs_its_own_right_and_is_audited(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);

        $this->actingAs($doctor)->get("/hospitalisation/selection/export?uuids[]={$stay->uuid}")->assertForbidden();

        $this->grant($doctor, 'hospitalization.export');
        $response = $this->actingAs($doctor->fresh())->get("/hospitalisation/selection/export?uuids[]={$stay->uuid}")->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'hospitalisation').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        unlink($path);

        $this->assertSame('N° patient', $rows[0][0]);
        $this->assertSame('RAKOTO Soa', $rows[1][1]);
        $this->assertSame($stay->episode->episode_number, $rows[1][4]);
        $this->assertSame('Déshydratation sévère', $rows[1][10]);

        $audit = AuditLog::query()->where('action', 'hospitalization.export')->sole();
        $this->assertSame(1, $audit->new_values['rows']);
    }

    public function test_the_selection_is_bounded_and_ignores_a_cancelled_stay(): void
    {
        $doctor = $this->hospitalDoctor();
        $kept = $this->admitted($doctor, 'Rakoto', 'Soa');
        $cancelled = $this->admitted($doctor, 'Rabe', 'Hery');
        $cancelled->update(['status' => HospitalStayStatus::Cancelled, 'active_key' => null]);

        $this->actingAs($doctor)->get("/hospitalisation/selection/regimes?uuids[]={$kept->uuid}&uuids[]={$cancelled->uuid}")
            ->assertInertia(fn (Assert $page) => $page->has('sheets', 1)->where('sheets.0.uuid', $kept->uuid));

        $tooMany = collect(range(1, 51))->map(fn () => 'uuids[]='.fake()->uuid())->implode('&');
        $this->actingAs($doctor)->from('/hospitalisation')->get("/hospitalisation/selection/regimes?{$tooMany}")
            ->assertRedirect('/hospitalisation')
            ->assertSessionHasErrors('uuids');

        $this->actingAs($doctor)->from('/hospitalisation')->get('/hospitalisation/selection/regimes')
            ->assertSessionHasErrors('uuids');
    }

    public function test_the_export_right_is_granted_to_medicine_and_nurse_by_default(): void
    {
        $grants = (new \ReflectionClassConstant(RolePermissionSeeder::class, 'GRANTS'))->getValue();

        $this->assertContains('hospitalization.export', $grants['MEDICINE']);
        $this->assertContains('hospitalization.export', $grants['NURSE']);
        $this->assertNotContains('hospitalization.export', $grants['RECEPTION']);
    }

    private function grant(User $user, string $permission): void
    {
        $user->role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $permission])->id]);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'VIEWER_'.uniqid(), 'name' => 'Lecture']);

        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name])->id);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
