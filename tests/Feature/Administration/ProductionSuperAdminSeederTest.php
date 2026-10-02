<?php

namespace Tests\Feature\Administration;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProductionSuperAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.bootstrap_super_admin' => [
                'email' => 'DG@Clinique.test',
                'name' => 'Directeur Général',
                'password' => 'Seeder-password1!',
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_it_creates_the_first_super_admin_from_the_environment(): void
    {
        $this->seed(ProductionSuperAdminSeeder::class);

        $user = User::query()->where('email', 'dg@clinique.test')->firstOrFail();

        $this->assertTrue($user->isActive());
        $this->assertSame('SUPER_ADMIN', $user->role->code);
        $this->assertTrue(Hash::check('Seeder-password1!', $user->password));
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.bootstrap_super_admin']);
    }

    public function test_it_never_touches_an_existing_super_admin(): void
    {
        $this->seed(ProductionSuperAdminSeeder::class);
        config(['rivo.bootstrap_super_admin.password' => 'Another-password2!']);

        $this->seed(ProductionSuperAdminSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('Seeder-password1!', User::query()->firstOrFail()->password));
    }

    public function test_it_refuses_a_weak_password_and_creates_nothing(): void
    {
        config(['rivo.bootstrap_super_admin.password' => 'password']);

        $this->seed(ProductionSuperAdminSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_does_nothing_on_a_clinic_site(): void
    {
        config(['rivo.site.type' => 'clinic']);

        $this->seed(ProductionSuperAdminSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_does_nothing_without_credentials(): void
    {
        config(['rivo.bootstrap_super_admin.password' => null]);

        $this->seed(ProductionSuperAdminSeeder::class);

        $this->assertSame(0, User::query()->count());
    }
}
