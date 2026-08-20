<?php

namespace Tests\Feature\Administration;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_secure_command_provisions_a_real_super_admin_without_hardcoded_password(): void
    {
        $this->artisan('rivo:provision-super-admin', [
            'email' => 'responsable@clinic.test',
            '--name' => 'Responsable Clinique',
        ])
            ->expectsQuestion('Mot de passe (12 caractères minimum, majuscule, minuscule, chiffre et symbole)', 'Command-password1!')
            ->expectsQuestion('Confirmez le mot de passe', 'Command-password1!')
            ->assertSuccessful();

        $user = User::query()->where('email', 'responsable@clinic.test')->firstOrFail();

        $this->assertTrue($user->isActive());
        $this->assertSame('SUPER_ADMIN', $user->role->code);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $user->id,
            'action' => 'user.bootstrap_super_admin',
            'module' => 'administration',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $user->id,
            'action' => 'user.role.assign',
        ]);
    }

    public function test_command_can_atomically_replace_known_demo_accounts(): void
    {
        $demo = User::factory()->create([
            'name' => 'Compte test',
            'email' => 'test@example.com',
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);

        $this->artisan('rivo:provision-super-admin', [
            'email' => 'direction@clinic.test',
            '--name' => 'Direction Clinique',
            '--replace-demo-users' => true,
        ])
            ->expectsQuestion('Mot de passe (12 caractères minimum, majuscule, minuscule, chiffre et symbole)', 'Command-password1!')
            ->expectsQuestion('Confirmez le mot de passe', 'Command-password1!')
            ->assertSuccessful();

        $replacement = User::query()->where('email', 'direction@clinic.test')->firstOrFail();

        $this->assertTrue($replacement->isActive());
        $this->assertFalse($demo->fresh()->active);
        $this->assertSame($replacement->id, $demo->fresh()->deactivated_by);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $replacement->id,
            'entity_id' => $demo->id,
            'action' => 'user.deactivate_demo',
        ]);
    }
}
