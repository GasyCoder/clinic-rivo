<?php

namespace Tests\Feature\Reception;

use App\Enums\PatientAgeBand;
use App\Enums\PatientType;
use App\Models\AppSetting;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\Patients\PatientAgeRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-184 — l'âge guide le formulaire d'un nouveau patient, avec les tranches
 * réglées pour le site : bébé et enfant ont le profil enfant, un bébé se
 * déclare avec sa date de naissance exacte, et une civilité qui contredit
 * l'âge est refusée — jamais corrigée en silence.
 */
class PatientAgeBandArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const BANDS = ['baby_max_age' => 1, 'child_max_age' => 15];

    public function test_the_bands_follow_the_settings_in_completed_years(): void
    {
        $this->assertSame(PatientAgeBand::Baby, PatientAgeBand::forYears(0, self::BANDS));
        $this->assertSame(PatientAgeBand::Baby, PatientAgeBand::forYears(1, self::BANDS));
        $this->assertSame(PatientAgeBand::Child, PatientAgeBand::forYears(2, self::BANDS));
        $this->assertSame(PatientAgeBand::Child, PatientAgeBand::forYears(15, self::BANDS));
        $this->assertSame(PatientAgeBand::Adult, PatientAgeBand::forYears(16, self::BANDS));
        $this->assertNull(PatientAgeBand::forYears(null, self::BANDS));

        // Vingt-trois mois : encore un bébé ; deux ans révolus : un enfant.
        $this->assertSame(PatientAgeBand::Baby, PatientAgeRules::band(now()->subMonths(23)->toDateString(), null, self::BANDS));
        $this->assertSame(PatientAgeBand::Child, PatientAgeRules::band(now()->subYears(2)->toDateString(), null, self::BANDS));
        $this->assertNull(PatientAgeRules::band(now()->addDay()->toDateString(), null, self::BANDS));
    }

    public function test_a_child_by_age_gets_the_child_profile_even_without_the_child_civility(): void
    {
        $actor = $this->receptionist();

        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'birth_date' => now()->subYears(7)->toDateString(),
            'phone' => '0340000000',
            'profession' => 'Élève',
        ])->assertSessionHasErrors(['phone', 'profession']);

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_an_adult_civility_for_a_child_is_refused_and_a_child_civility_for_an_adult_too(): void
    {
        $actor = $this->receptionist();

        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'MRS',
            'birth_date' => now()->subYears(9)->toDateString(),
        ])->assertSessionHasErrors(['civility' => 'Ce patient est un enfant (jusqu’à 15 ans) : choisissez « Enfant fille » ou « Enfant garçon ».']);

        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'GIRL',
            'birth_date' => '1990-03-02',
        ])->assertSessionHasErrors(['civility' => 'La civilité « Enfant » ne convient pas à un adulte (16 ans et plus) : choisissez M. ou Mme.']);

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_a_baby_is_declared_with_an_exact_birth_date_not_an_age(): void
    {
        $actor = $this->receptionist();

        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'BOY',
            'sex' => 'M',
            'birth_date' => null,
            'age' => 0,
        ])->assertSessionHasErrors(['birth_date']);

        $this->assertSame(0, Patient::query()->count());

        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'BOY',
            'sex' => 'M',
            'birth_date' => now()->subMonths(5)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('BOY', Patient::query()->sole()->civility?->value);
    }

    public function test_the_bands_set_for_the_site_are_the_ones_applied(): void
    {
        AppSetting::query()->create(['baby_max_age' => 2, 'child_max_age' => 12]);
        app(AppSettings::class)->forget();

        $actor = $this->receptionist();

        // Quatorze ans : un adulte pour ce site, donc « Mme » est accepté.
        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'MRS',
            'birth_date' => now()->subYears(14)->toDateString(),
        ])->assertSessionHasNoErrors();

        // Deux ans : un bébé pour ce site, donc un âge seul ne suffit pas.
        $this->actingAs($actor)->post('/reception/patients', [
            ...$this->patient(),
            'last_name' => 'Rabe',
            'civility' => 'GIRL',
            'birth_date' => null,
            'age' => 2,
        ])->assertSessionHasErrors(['birth_date']);
    }

    public function test_an_adult_arrival_is_unchanged(): void
    {
        $this->actingAs($this->receptionist())->post('/reception/patients', [
            ...$this->patient(),
            'civility' => 'MRS',
            'birth_date' => '1985-07-17',
            'phone' => '0340000000',
            'profession' => 'Enseignante',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0340000000', Patient::query()->sole()->phone);
    }

    private function receptionist(): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach (['patients.create', 'episodes.create'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array<string, mixed> */
    private function patient(): array
    {
        return [
            'patient_type' => PatientType::Standard->value,
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1985-07-17',
            'sex' => 'F',
        ];
    }
}
