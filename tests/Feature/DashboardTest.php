<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
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
}
