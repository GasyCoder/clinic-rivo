<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_the_pharmacy_tasks_are_on_the_overview_instead_of_a_second_home(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $pharmacist = User::factory()->create(['role_id' => Role::query()->where('code', 'PHARMACY')->value('id')]);
        $receptionist = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        $this->actingAs($pharmacist)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->where('pharmacy.capabilities.can_view_stock', true)
                ->has('pharmacy.alerts'));

        $this->actingAs($pharmacist)->get('/pharmacy')->assertRedirect('/');

        $this->actingAs($receptionist)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pharmacy', null));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->withRole()->create();
        Patient::query()->create([
            'patient_number' => 'A-26-HIDDEN',
            'first_name' => 'Invisible',
            'last_name' => 'Sans permission',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Home')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.email', $user->email)
            ->has('overview.generated_at')
            ->where('overview.metrics', [])
            ->where('overview.patient_demographics', null)
            ->has('overview.trend.dates', 7)
            ->where('overview.trend.series', [])
        );
    }

    public function test_operational_overview_only_returns_indicators_allowed_by_permissions(): void
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $permission = Permission::query()->create([
            'name' => 'patients.view',
            'label' => 'Voir les patients',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id]);

        Patient::query()->create([
            'patient_number' => 'A-26-0001',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $historicalPatient = Patient::query()->create([
            'patient_number' => 'A-26-0002',
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1985-02-03',
            'sex' => 'F',
        ]);
        DB::table('patients')->where('id', $historicalPatient->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home')
                ->has('overview.metrics', 1)
                ->where('overview.metrics.0.key', 'patients_today')
                ->where('overview.metrics.0.value', 1)
                ->where('overview.metrics.0.href', '/patients')
                ->has('overview.trend.dates', 7)
                ->has('overview.trend.series', 1)
                ->where('overview.trend.series.0.key', 'patients')
                ->where('overview.trend.series.0.total', 2)
                ->where('overview.trend.series.0.values', [0, 0, 0, 0, 1, 0, 1]));
    }

    public function test_patient_demographics_are_permission_scoped_and_use_exact_or_declared_age(): void
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $permission = Permission::query()->create([
            'name' => 'patients.view',
            'label' => 'Voir les patients',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id]);

        foreach ([
            ['patient_number' => 'A-26-MAN', 'first_name' => 'Hery', 'sex' => 'M', 'birth_date' => now()->subYears(30)->toDateString()],
            ['patient_number' => 'A-26-WOMAN', 'first_name' => 'Fara', 'sex' => 'F', 'birth_date' => null, 'declared_age' => 24],
            ['patient_number' => 'A-26-BOY', 'first_name' => 'Koto', 'sex' => 'M', 'birth_date' => now()->subYears(8)->toDateString()],
            ['patient_number' => 'A-26-GIRL', 'first_name' => 'Soa', 'sex' => 'F', 'birth_date' => null, 'declared_age' => 12],
            ['patient_number' => 'A-26-UNKNOWN', 'first_name' => 'Tiana', 'sex' => 'F', 'birth_date' => null, 'declared_age' => null],
        ] as $patient) {
            Patient::query()->create(array_merge([
                'last_name' => 'Test',
            ], $patient));
        }

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('overview.patient_demographics.total', 5)
                ->where('overview.patient_demographics.segments.0', ['key' => 'men', 'label' => 'Hommes adultes', 'value' => 1])
                ->where('overview.patient_demographics.segments.1', ['key' => 'women', 'label' => 'Femmes adultes', 'value' => 1])
                ->where('overview.patient_demographics.segments.2', ['key' => 'children', 'label' => 'Enfants', 'value' => 2])
                ->where('overview.patient_demographics.segments.3', ['key' => 'unclassified', 'label' => 'Âge non renseigné', 'value' => 1])
                ->where('overview.patient_demographics.children', ['total' => 2, 'boys' => 1, 'girls' => 1]));
    }
}
