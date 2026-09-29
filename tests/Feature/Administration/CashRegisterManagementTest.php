<?php

namespace Tests\Feature\Administration;

use App\Models\CashRegister;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    public function test_only_administration_manages_registers_while_reception_only_views_them(): void
    {
        $admin = $this->user('ADMINISTRATION');

        $this->actingAs($admin)->get('/administration/cash-registers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Administration/CashRegisters/Index'));

        $this->actingAs($this->user('RECEPTION'))
            ->post('/administration/cash-registers', ['name' => 'Caisse 1'])
            ->assertForbidden();

        foreach (['MEDICINE', 'PHARMACY', 'SURGERY', 'LABORATORY'] as $roleCode) {
            $this->actingAs($this->user($roleCode))
                ->get('/administration/cash-registers')
                ->assertForbidden();
        }
    }

    public function test_administration_creates_renames_and_archives_but_cannot_restore_a_register(): void
    {
        $admin = $this->user('ADMINISTRATION');

        $this->actingAs($admin)->post('/administration/cash-registers', ['name' => 'Caisse 1'])
            ->assertRedirect();

        $register = CashRegister::query()->sole();
        $this->assertSame('Caisse 1', $register->name);
        $this->assertTrue($register->active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'cash',
            'entity_id' => $register->id,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post('/administration/cash-registers', ['name' => 'caisse 1 '])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->put("/administration/cash-registers/{$register->uuid}", ['name' => 'Caisse principale'])
            ->assertRedirect();
        $this->assertSame('Caisse principale', $register->fresh()->name);

        $this->actingAs($admin)->delete("/administration/cash-registers/{$register->uuid}", ['reason' => 'Fusionnée avec une autre caisse'])
            ->assertRedirect();

        $register->refresh();
        $this->assertFalse($register->active);
        $this->assertNotNull($register->deleted_at);
        $this->assertSame('Fusionnée avec une autre caisse', $register->delete_reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete',
            'module' => 'cash',
            'entity_id' => $register->id,
            'reason' => 'Fusionnée avec une autre caisse',
        ]);

        $this->actingAs($admin)->post("/administration/cash-registers/{$register->uuid}/restore")
            ->assertForbidden();

        $register->refresh();
        $this->assertFalse($register->active);
        $this->assertNotNull($register->deleted_at);
    }

    public function test_a_register_with_an_open_session_cannot_be_archived(): void
    {
        $admin = $this->user('ADMINISTRATION');
        $receptionist = $this->user('RECEPTION');
        $register = CashRegister::query()->create(['name' => 'Caisse 1']);

        $this->actingAs($receptionist)->post('/cash/open', [
            'opening_amount' => '0',
            'cash_register_uuid' => $register->uuid,
        ])->assertRedirect();

        $this->actingAs($admin)->delete("/administration/cash-registers/{$register->uuid}", ['reason' => 'Test de fermeture forcée'])
            ->assertSessionHasErrors('register');

        $this->assertTrue($register->fresh()->active);
    }

    public function test_administration_deactivates_and_reactivates_a_register_without_archiving_it(): void
    {
        $admin = $this->user('ADMINISTRATION');
        $registerOne = CashRegister::query()->create(['name' => 'Caisse 1']);
        $registerTwo = CashRegister::query()->create(['name' => 'Caisse 2']);

        $this->actingAs($admin)->post("/administration/cash-registers/{$registerOne->uuid}/deactivate")
            ->assertRedirect();

        $registerOne->refresh();
        $this->assertFalse($registerOne->active);
        $this->assertNull($registerOne->deleted_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'module' => 'cash',
            'entity_id' => $registerOne->id,
            'user_id' => $admin->id,
        ]);

        // A deactivated register drops out of the site's own picker while
        // the still-active one keeps appearing normally.
        $this->actingAs($this->user('RECEPTION'))->get('/cash')
            ->assertInertia(fn ($page) => $page
                ->component('Cash/Index')
                ->has('registers', 1)
                ->where('registers.0.uuid', $registerTwo->uuid));

        $this->actingAs($admin)->post("/administration/cash-registers/{$registerOne->uuid}/activate")
            ->assertRedirect();

        $registerOne->refresh();
        $this->assertTrue($registerOne->active);
        $this->assertNull($registerOne->deleted_at);
    }

    public function test_a_register_with_an_open_session_cannot_be_deactivated(): void
    {
        $admin = $this->user('ADMINISTRATION');
        $receptionist = $this->user('RECEPTION');
        $register = CashRegister::query()->create(['name' => 'Caisse 1']);

        $this->actingAs($receptionist)->post('/cash/open', [
            'opening_amount' => '0',
            'cash_register_uuid' => $register->uuid,
        ])->assertRedirect();

        $this->actingAs($admin)->post("/administration/cash-registers/{$register->uuid}/deactivate")
            ->assertSessionHasErrors('register');

        $this->assertTrue($register->fresh()->active);
    }

    public function test_reception_cannot_activate_or_deactivate_registers(): void
    {
        $register = CashRegister::query()->create(['name' => 'Caisse 1']);

        $this->actingAs($this->user('RECEPTION'))
            ->post("/administration/cash-registers/{$register->uuid}/deactivate")
            ->assertForbidden();

        $this->assertTrue($register->fresh()->active);
    }

    public function test_an_assigned_register_only_opens_for_its_reception_cashier_and_uses_its_fixed_fund(): void
    {
        $admin = $this->user('ADMINISTRATION');
        $cashier = $this->user('RECEPTION');
        $colleague = $this->user('RECEPTION');

        $this->actingAs($admin)->post('/administration/cash-registers', [
            'name' => 'Caisse bleue',
            'color' => '#2563EB',
            'opening_fund_amount' => '25000.00',
            'assigned_user_uuid' => $cashier->uuid,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $register = CashRegister::query()->sole();
        $this->assertSame($cashier->id, $register->assigned_user_id);
        $this->assertSame('#2563EB', $register->color);
        $this->assertSame('25000.00', $register->opening_fund_amount);

        $this->actingAs($colleague)->get('/cash')
            ->assertInertia(fn ($page) => $page
                ->where('registers.0.color', '#2563EB')
                ->where('registers.0.opening_fund_amount', '25000.00')
                ->where('registers.0.is_assigned_to_me', false)
                ->where('registers.0.assigned_user_name', $cashier->name));

        $this->actingAs($colleague)->get("/cash/{$register->uuid}")
            ->assertRedirect('/cash')
            ->assertSessionHas('status_type', 'warning');

        $this->actingAs($colleague)->post('/cash/open', [
            'opening_amount' => '1000.00',
            'cash_register_uuid' => $register->uuid,
        ])->assertSessionHasErrors('cash_register_uuid');

        $this->actingAs($cashier)->get('/cash')
            ->assertInertia(fn ($page) => $page
                ->where('registers.0.is_assigned_to_me', true)
                ->where('registers.0.assigned_user_name', $cashier->name));

        $this->actingAs($cashier)->post('/cash/open', [
            // The configured amount is authoritative, even if a stale or
            // forged client sends another value.
            'opening_amount' => '1000.00',
            'cash_register_uuid' => $register->uuid,
        ])->assertRedirect("/cash/{$register->uuid}")->assertSessionHasNoErrors();

        $this->assertSame('25000.00', $register->sessions()->sole()->opening_amount);

        $this->actingAs($admin)->put("/administration/cash-registers/{$register->uuid}", [
            'name' => 'Caisse bleue',
            'color' => '#0F766E',
            'opening_fund_amount' => '30000.00',
            'assigned_user_uuid' => $colleague->uuid,
        ])->assertSessionHasErrors('assigned_user_uuid');

        $this->assertSame($cashier->id, $register->fresh()->assigned_user_id);
    }

    public function test_a_register_rejects_a_holder_outside_reception_cash(): void
    {
        $admin = $this->user('ADMINISTRATION');
        $laboratory = $this->user('LABORATORY');

        $this->actingAs($admin)->post('/administration/cash-registers', [
            'name' => 'Caisse laboratoire',
            'color' => '#7C3AED',
            'opening_fund_amount' => '0',
            'assigned_user_uuid' => $laboratory->uuid,
        ])->assertSessionHasErrors('assigned_user_uuid');

        $this->assertDatabaseCount('cash_registers', 0);
    }
}
