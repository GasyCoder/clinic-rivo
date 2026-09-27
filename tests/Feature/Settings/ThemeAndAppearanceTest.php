<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Settings\ThemeColor;
use App\Support\Settings\ThemePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-191 — le thème d'un site (couleurs du mode clair et du mode sombre),
 * ses réglages avancés par défaut, et ce que chacun ajuste pour lui-même dans
 * « Mon profil » : taille du texte, animations, contraste.
 */
class ThemeAndAppearanceTest extends TestCase
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

    public function test_every_proposed_theme_is_readable_in_both_modes(): void
    {
        foreach (ThemePresets::all() as $key => $preset) {
            foreach (['light', 'dark'] as $mode) {
                $ratio = ThemeColor::contrastBetween(
                    ThemeColor::fromHex($preset[$mode]['background']),
                    ThemeColor::fromHex($preset[$mode]['foreground']),
                );

                $this->assertGreaterThanOrEqual(7, $ratio, "{$key} ({$mode}) n’est pas assez lisible");
            }
        }
    }

    public function test_a_theme_whose_text_would_be_unreadable_is_refused(): void
    {
        $this->withHeaders($this->headers())
            ->putJson(self::URL, [...$this->valid(), 'light_background' => '#FFFFFF', 'light_foreground' => '#DDDDDD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['light_foreground']);

        // Une seule couleur réglée se juge contre la couleur d'origine de l'autre.
        $this->withHeaders($this->headers())
            ->putJson(self::URL, [...$this->valid(), 'dark_background' => '#E3E4E6'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dark_background']);

        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_a_saved_theme_redefines_both_modes_and_reaches_the_page(): void
    {
        $night = ThemePresets::all()['night'];

        $this->withHeaders($this->headers())
            ->putJson(self::URL, [
                ...$this->valid(),
                'theme_preset' => 'night',
                'primary_color' => $night['light']['primary'],
                'light_background' => $night['light']['background'],
                'light_foreground' => $night['light']['foreground'],
                'dark_primary_color' => $night['dark']['primary'],
                'dark_background' => $night['dark']['background'],
                'dark_foreground' => $night['dark']['foreground'],
            ])
            ->assertOk()
            ->assertJsonPath('data.values.theme_preset', 'night');

        $settings = app(AppSettings::class);
        $settings->forget();
        $css = $settings->themeCss();

        $this->assertStringContainsString(':root{--primary:'.ThemeColor::fromHex($night['light']['primary'])->triplet(), $css);
        $this->assertStringContainsString('--background:'.ThemeColor::fromHex($night['light']['background'])->triplet(), $css);
        $this->assertStringContainsString('.dark{--primary:'.ThemeColor::fromHex($night['dark']['primary'])->triplet(), $css);
        $this->assertStringContainsString('--background:'.ThemeColor::fromHex($night['dark']['background'])->triplet(), $css);

        $this->actingAs(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertOk()
            ->assertSee('<style id="rivo-theme">:root{--primary:', false);
    }

    public function test_the_site_defaults_are_applied_on_the_page_before_any_script(): void
    {
        AppSetting::query()->create(['ui_font_size' => 17, 'ui_density' => 'compact', 'ui_radius' => 'round', 'ui_contrast' => 'high']);

        $this->actingAs(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertOk()
            ->assertSee('data-density="compact"', false)
            ->assertSee('data-radius="round"', false)
            ->assertSee('data-contrast="high"', false)
            ->assertSee('data-motion="system"', false)
            ->assertSee('style="font-size: 106.25%"', false)
            ->assertInertia(fn ($page) => $page
                ->where('appearance.site.density', 'compact')
                ->where('appearance.effective.font_size', 17)
                ->where('appearance.user.font_size', null));
    }

    public function test_each_user_adjusts_text_size_motion_and_contrast_for_themselves_only(): void
    {
        AppSetting::query()->create(['ui_density' => 'compact']);
        $user = User::factory()->withRole()->create();
        $colleague = User::factory()->withRole()->create();

        $this->actingAs($user)
            ->put('/profil/apparence', ['font_size' => 18, 'motion' => 'reduce', 'contrast' => 'max', 'density' => 'comfortable'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Apparence enregistrée.');

        // La densité appartient au site : elle n'est pas gardée sur le compte.
        $this->assertSame(['font_size' => 18, 'motion' => 'reduce', 'contrast' => 'max'], $user->fresh()->ui_preferences);

        $this->actingAs($user)->get('/profil')
            ->assertSee('data-contrast="max"', false)
            ->assertSee('data-motion="reduce"', false)
            ->assertSee('data-density="compact"', false)
            ->assertSee('style="font-size: 112.5%"', false)
            ->assertInertia(fn ($page) => $page->where('appearance.effective.font_size', 18));

        // Sur un poste partagé, le réglage suit le compte, pas l'écran.
        $this->actingAs($colleague)->get('/profil')
            ->assertSee('data-contrast="standard"', false)
            ->assertDontSee('style="font-size:', false);
    }

    public function test_an_invalid_personal_value_is_refused_and_an_empty_one_restores_the_site(): void
    {
        $user = User::factory()->withRole()->create(['ui_preferences' => ['font_size' => 14]]);

        $this->actingAs($user)
            ->put('/profil/apparence', ['font_size' => 30, 'contrast' => 'neon'])
            ->assertSessionHasErrors(['font_size', 'contrast']);
        $this->assertSame(['font_size' => 14], $user->fresh()->ui_preferences);

        $this->actingAs($user)
            ->put('/profil/apparence', ['font_size' => null, 'motion' => null, 'contrast' => null])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Apparence du site rétablie.');
        $this->assertNull($user->fresh()->ui_preferences);
    }

    public function test_a_guest_cannot_change_an_appearance(): void
    {
        $this->put('/profil/apparence', ['font_size' => 18])->assertRedirect('/login');
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
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => 'settings.update,settings.view',
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
