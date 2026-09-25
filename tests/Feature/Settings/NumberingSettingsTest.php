<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\User;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Episode\EpisodeNumberGenerator;
use App\Services\Patient\PatientNumberGenerator;
use App\Services\Settings\AppSettings;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-191 — la forme des numéros de patient et de passage, et le matricule
 * proposé aux RH, réglés par site. Un réglage ne vaut que pour les numéros à
 * venir : aucun numéro attribué n'est réécrit, et un numéro qui existe déjà
 * n'est jamais redonné.
 */
class NumberingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/app-settings';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_unconfigured_site_keeps_the_numbers_of_adr_030(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $patients = $this->patients();

        $mother = $this->patient($patients->next());
        $this->assertSame('A-26-0001', $mother->patient_number);
        $this->assertSame('A-26-0001-01', $this->episodes()->next($mother));
        $this->assertSame('A-26-0001-B1', $patients->newborn($mother, 1));
        $this->assertSame('EMP-0001', $this->allocator()->suggest());
    }

    public function test_prefix_year_digits_and_separator_shape_every_new_number(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        AppSetting::query()->create([
            'patient_number_prefix' => 'CSG',
            'patient_number_year' => '4',
            'patient_number_digits' => 5,
            'patient_number_separator' => '/',
            'episode_number_digits' => 3,
        ]);

        $patients = $this->patients();
        $mother = $this->patient($patients->next());
        $episode = $this->episodes()->next($mother);

        $this->assertSame('CSG/2026/00001', $mother->patient_number);
        $this->assertSame('CSG/2026/00001/001', $episode);
        // Le rang du passage se relit quel que soit le séparateur réglé.
        $this->assertSame(1, $this->episodes()->sequenceFromNumber($mother, $episode));
        $this->assertSame('CSG/2026/00001/B1', $patients->newborn($mother, 1));
    }

    public function test_a_number_without_year_uses_one_counter_that_never_restarts(): void
    {
        AppSetting::query()->create(['patient_number_year' => 'none', 'patient_number_reset' => 'yearly']);
        $patients = $this->patients();

        Carbon::setTestNow('2026-12-31 23:00:00');
        $this->assertSame('A-0001', $patients->next());

        // Une remise à 1 chaque année redonnerait « A-0001 » : sans année, le compteur continue.
        Carbon::setTestNow('2027-01-01 08:00:00');
        $this->assertSame('A-0002', $patients->next());
    }

    public function test_a_continuous_counter_keeps_counting_across_years(): void
    {
        AppSetting::query()->create(['patient_number_reset' => 'never']);
        $patients = $this->patients();

        Carbon::setTestNow('2026-12-31 23:00:00');
        $this->assertSame('A-26-0001', $patients->next());

        Carbon::setTestNow('2027-01-01 08:00:00');
        $this->assertSame('A-27-0002', $patients->next());
    }

    public function test_a_number_already_given_is_skipped_and_never_rewritten(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        // Un ancien format a déjà produit la chaîne que le nouveau produirait.
        $existing = $this->patient('A-0001');
        AppSetting::query()->create(['patient_number_year' => 'none', 'patient_number_reset' => 'never']);

        $patients = $this->patients();
        $this->assertSame('A-0002', $patients->peek(), 'l’aperçu saute lui aussi un numéro pris');
        $this->assertSame('A-0002', $patients->next());
        $this->assertSame('A-0001', $existing->fresh()->patient_number);

        // Changer la forme ensuite ne touche à aucun numéro déjà attribué.
        AppSetting::query()->firstOrFail()->update(['patient_number_prefix' => 'B']);
        $this->assertSame('B-0003', $this->patients()->next());
        $this->assertSame('A-0001', $existing->fresh()->patient_number);
    }

    public function test_the_settings_api_refuses_a_yearly_restart_without_a_year_and_out_of_range_values(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::URL, [
                ...$this->valid(),
                'patient_number_year' => 'none',
                'patient_number_reset' => 'yearly',
                'patient_number_prefix' => 'A-B',
                'patient_number_digits' => 12,
                'employee_number_separator' => '#',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'patient_number_reset', 'patient_number_prefix', 'patient_number_digits', 'employee_number_separator',
            ]);

        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_the_settings_api_saves_the_numbering_and_previews_the_next_numbers(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::URL, [
                ...$this->valid(),
                'patient_number_prefix' => 'amb',
                'patient_number_year' => '4',
                'patient_number_separator' => '.',
                'employee_number_prefix' => 'rh',
                'employee_number_separator' => '/',
                'employee_number_digits' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.values.patient_number_prefix', 'AMB')
            ->assertJsonPath('data.values.employee_number_prefix', 'RH')
            ->assertJsonPath('data.numbering.patient_next', 'AMB.2026.0001')
            ->assertJsonPath('data.numbering.employee_next', 'RH/001');

        // L'aperçu ne consomme rien : le premier vrai numéro est toujours le premier.
        $this->assertSame('AMB.2026.0001', $this->patients()->next());
    }

    public function test_the_employee_number_follows_the_highest_one_of_the_model_archives_included(): void
    {
        Employee::query()->create($this->employeePayload('EMP-0007'))->delete();
        Employee::query()->create($this->employeePayload('RH-0100'));

        $this->assertSame('EMP-0008', $this->allocator()->suggest(), 'un matricule archivé n’est jamais redonné ; un autre modèle ne compte pas');
        // Un import qui écrit déjà EMP-0009 : ses lignes sans matricule viennent après lui.
        $this->assertSame(['EMP-0010', 'EMP-0011'], $this->allocator()->sequence(2, ['EMP-0009']));

        AppSetting::query()->create(['employee_number_prefix' => 'RH', 'employee_number_separator' => '-', 'employee_number_digits' => 4]);
        $this->assertSame('RH-0101', $this->allocator()->suggest());
    }

    public function test_an_employee_created_without_a_number_receives_the_proposed_one(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $actor = $this->administration();
        Employee::query()->create($this->employeePayload('EMP-0004'));

        $this->actingAs($actor)->get('/administration/employees/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Administration/Employees/Create')
            ->where('suggestedEmployeeNumber', 'EMP-0005')
            ->where('employeeNumberModel', 'EMP-0000'));

        $this->actingAs($actor)
            ->post('/administration/employees', ['employee_number' => '', ...$this->employeePayload(null, 'Rasoa')])
            ->assertSessionHasNoErrors();

        $this->assertSame('EMP-0005', Employee::query()->where('last_name', 'Rasoa')->value('employee_number'));

        // Un matricule saisi reste celui du RH : c'est une proposition, pas une règle.
        $this->actingAs($actor)
            ->post('/administration/employees', $this->employeePayload('INT-42', 'Rakoto'))
            ->assertSessionHasNoErrors();
        $this->assertSame('INT-42', Employee::query()->where('last_name', 'Rakoto')->value('employee_number'));
    }

    public function test_an_import_line_without_a_number_receives_the_next_one_after_those_of_the_file(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);

        $csv = implode("\n", [
            'MATRICULE,NOM,GENRE,STATUS',
            'EMP-0003,Rabe,Femme,Actif',
            ',Rakoto,Homme,Actif',
            ',Rasoa,Femme,Actif',
        ]);

        $this->actingAs($this->administration())->post('/administration/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('personnel.csv', $csv),
        ])->assertSessionHasNoErrors();

        $numbers = Employee::query()->pluck('employee_number', 'last_name');
        $this->assertSame('EMP-0003', $numbers['Rabe']);
        $this->assertSame('EMP-0004', $numbers['Rakoto']);
        $this->assertSame('EMP-0005', $numbers['Rasoa']);
    }

    private function patients(): PatientNumberGenerator
    {
        return new PatientNumberGenerator(new AppSettings);
    }

    private function episodes(): EpisodeNumberGenerator
    {
        return new EpisodeNumberGenerator(new AppSettings);
    }

    private function allocator(): EmployeeNumberAllocator
    {
        return new EmployeeNumberAllocator(new AppSettings);
    }

    private function patient(string $number): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => 'Voahangy',
            'last_name' => 'Rasoa',
            'birth_date' => '1994-03-02',
            'sex' => 'F',
        ]);
    }

    private function administration(): User
    {
        return User::factory()->withRole('ADMINISTRATION')->create();
    }

    /** @return array<string, mixed> */
    private function employeePayload(?string $number, string $lastName = 'Rabe'): array
    {
        return array_filter([
            'employee_number' => $number,
            'civility' => 'MRS',
            'first_name' => 'Soa',
            'last_name' => $lastName,
            'sex' => 'F',
            'active' => true,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'currency_label' => 'Ar',
            'currency_position' => 'after',
            'currency_decimals' => 0,
            'baby_max_age' => 1,
            'child_max_age' => 15,
        ];
    }

    /** @return array<string, string> */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
