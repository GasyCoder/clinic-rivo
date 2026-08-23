<?php

namespace Tests\Feature\Administration;

use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentUserSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class DevelopmentUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.seeders.development_users_password' => 'Seeder-password1!',
        ]);
        $this->app['env'] = 'testing';

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ProfessionalProfileSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_it_creates_every_operational_role_and_professional_profile_for_local_development(): void
    {
        $this->seed(DevelopmentUserSeeder::class);

        $this->assertDatabaseCount('users', 13);
        $this->assertDatabaseMissing('users', [
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);

        $expectedRoles = [
            'ADMINISTRATION', 'LOGISTICS', 'SUPPORT', 'MAINTENANCE',
            'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY',
        ];

        foreach ($expectedRoles as $roleCode) {
            $this->assertDatabaseHas('users', [
                'role_id' => Role::query()->where('code', $roleCode)->value('id'),
                'active' => true,
            ]);
        }

        foreach (['REGISTERED_NURSE', 'MIDWIFE', 'ANESTHETIST', 'GUARD', 'CLEANER', 'IT_TECHNICIAN'] as $profileCode) {
            $this->assertDatabaseHas('users', [
                'professional_profile_id' => ProfessionalProfile::query()
                    ->where('code', $profileCode)
                    ->value('id'),
            ]);
        }

        User::query()->each(function (User $user): void {
            $this->assertStringEndsWith('.a@rivo.test', $user->email);
            $this->assertTrue(Hash::check('Seeder-password1!', $user->password));
        });
    }

    public function test_profile_permissions_are_individual_and_do_not_leak_to_colleagues(): void
    {
        $this->seed(DevelopmentUserSeeder::class);

        $anesthetist = User::query()->where('email', 'anesthesiste.a@rivo.test')->firstOrFail();
        $nurse = User::query()->where('email', 'infirmiere.a@rivo.test')->firstOrFail();
        $guard = User::query()->where('email', 'gardien.a@rivo.test')->firstOrFail();
        $cleaner = User::query()->where('email', 'entretien.a@rivo.test')->firstOrFail();
        $technician = User::query()->where('email', 'technicien-informatique.a@rivo.test')->firstOrFail();

        $this->assertTrue($anesthetist->hasPermissionTo('anesthesia.validate'));
        $this->assertFalse($nurse->hasPermissionTo('anesthesia.validate'));
        $this->assertTrue($guard->hasPermissionTo('guarding.entries.create'));
        $this->assertFalse($cleaner->hasPermissionTo('guarding.entries.create'));
        $this->assertTrue($technician->hasPermissionTo('equipment.maintenance.manage'));
    }

    public function test_it_is_idempotent_and_resets_only_its_development_fixtures(): void
    {
        $this->seed(DevelopmentUserSeeder::class);
        $user = User::query()->where('email', 'reception.a@rivo.test')->firstOrFail();
        $user->forceFill(['password' => 'Temporary-password1!'])->save();

        $this->seed(DevelopmentUserSeeder::class);

        $this->assertDatabaseCount('users', 13);
        $this->assertSame($user->id, User::query()->where('email', $user->email)->value('id'));
        $this->assertTrue(Hash::check('Seeder-password1!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $user->id,
            'action' => 'user.development_seed.reset',
            'module' => 'administration',
        ]);
    }

    public function test_admin_development_deployment_gets_only_its_test_super_admin(): void
    {
        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
        ]);

        $this->seed(DevelopmentUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'email' => 'superadmin.admin@rivo.test',
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
            'active' => true,
        ]);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $this->expectException(LogicException::class);
        $this->seed(DevelopmentUserSeeder::class);
    }
}
