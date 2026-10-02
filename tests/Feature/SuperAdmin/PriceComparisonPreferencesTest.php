<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use App\Support\Pharmacy\PriceComparisonPreferences;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-242 — couleurs et marquage des prix du comparateur, propres au compte. */
class PriceComparisonPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rivo.site.type' => 'admin']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->user = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
    }

    public function test_only_what_differs_from_the_defaults_is_kept_on_the_account(): void
    {
        $this->user->forceFill(['ui_preferences' => ['font_size' => 18]])->save();

        $this->actingAs($this->user)
            ->put('/profil/comparateur-prix', [...PriceComparisonPreferences::DEFAULTS, 'worst_color' => '#7c3aed', 'min_gap_percent' => 15, 'sort_by_price' => false])
            ->assertSessionHasNoErrors();

        $stored = $this->user->fresh()->ui_preferences;
        $this->assertSame(18, $stored['font_size']);
        $this->assertSame(['worst_color' => '#7C3AED', 'min_gap_percent' => 15, 'sort_by_price' => false], $stored['price_comparison']);
        $this->assertSame('#7C3AED', PriceComparisonPreferences::resolve($stored)['worst_color']);
        $this->assertSame('#059669', PriceComparisonPreferences::resolve($stored)['best_color']);
    }

    public function test_a_bad_colour_is_refused_and_reset_restores_the_defaults(): void
    {
        $this->actingAs($this->user)->put('/profil/comparateur-prix', ['best_color' => 'vert'])->assertSessionHasErrors('best_color');

        $this->actingAs($this->user)->put('/profil/comparateur-prix', ['best_color' => '#000000']);
        $this->actingAs($this->user)->put('/profil/comparateur-prix', ['reset' => true])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('price_comparison', $this->user->fresh()->ui_preferences ?? []);
    }

    public function test_saving_the_appearance_keeps_the_price_settings(): void
    {
        $this->user->forceFill(['ui_preferences' => ['price_comparison' => ['style' => 'TEXT']]])->save();

        $this->actingAs($this->user)->put('/profil/apparence', ['font_size' => 18])->assertSessionHasNoErrors();

        $this->assertSame(['style' => 'TEXT'], $this->user->fresh()->ui_preferences['price_comparison']);
    }
}
