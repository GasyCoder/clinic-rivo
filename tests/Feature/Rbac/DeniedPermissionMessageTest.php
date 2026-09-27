<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-154 — un refus dit ce qui manque, et où le régler.
 */
class DeniedPermissionMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refusal_names_the_missing_permission(): void
    {
        $user = $this->accountWithout();

        $this->actingAs($user)
            ->get('/hospitalisation')
            ->assertForbidden()
            ->assertSee('hospitalization.view', escape: false)
            ->assertSee('Rôles &amp; permissions', escape: false);
    }

    /**
     * Le cas qui l'a motivé : le droit est coché au socle du rôle, et refusé
     * nominativement sur le compte. Le refus est exact (DENY > socle, ADR-033)
     * et se lisait pourtant comme un défaut de l'application.
     */
    public function test_a_personal_refusal_says_so_and_where_to_lift_it(): void
    {
        $user = $this->accountWithout();
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'hospitalization.view'],
            ['label' => 'Consulter les patients hospitalisés et leur fiche de régime'],
        );
        // Accordé au rôle…
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);
        // … et refusé au compte.
        $user->permissions()->attach($permission->id, ['effect' => 'deny']);

        $response = $this->actingAs($user->fresh())->get('/hospitalisation')->assertForbidden();

        $response->assertSee('refusé personnellement', escape: false);
        $response->assertSee('Exceptions par compte', escape: false);
        $response->assertSee('Selon le rôle', escape: false);
        // Le libellé du catalogue, pas seulement le code : c'est ce que lit
        // la personne qui ira cocher la case.
        $response->assertSee('Consulter les patients hospitalisés', escape: false);
    }

    private function accountWithout(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'RECEPTION'], ['name' => 'Réception']);

        return User::factory()->create(['role_id' => $role->id]);
    }
}
