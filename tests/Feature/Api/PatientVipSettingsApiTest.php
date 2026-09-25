<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PatientVipSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-133 — les seuils des patients VIP d'un site, réglés depuis le portail par
 * l'API du site : réautorisés localement, audités avec l'identité centrale, et
 * jamais écrits par un accès direct à la base.
 */
class PatientVipSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/patient-vip-settings';

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

    public function test_an_unconfigured_site_reports_no_setting_and_no_vip(): void
    {
        $this->withHeaders($this->headers(['patient_vip.view']))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.min_episodes', null)
            ->assertJsonPath('data.vip_count', 0);
    }

    public function test_reading_and_writing_each_need_their_own_permission(): void
    {
        $this->withHeaders($this->headers([]))->getJson(self::URL)->assertForbidden();

        // Voir n'est pas régler.
        $this->withHeaders($this->headers(['patient_vip.view'], (string) Str::uuid()))
            ->putJson(self::URL, $this->valid())
            ->assertForbidden();

        $this->assertSame(0, PatientVipSetting::query()->count());
    }

    public function test_the_thresholds_are_saved_once_replaced_in_place_and_audited_with_the_central_actor(): void
    {
        $actor = (string) Str::uuid();

        $this->withHeaders($this->headers(['patient_vip.update'], (string) Str::uuid(), $actor))
            ->putJson(self::URL, $this->valid())
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.min_episodes', 5)
            ->assertJsonPath('data.min_amount', '1000000.00')
            ->assertJsonPath('data.window_months', 12)
            ->assertJsonPath('data.updated_by', 'Direction centrale');

        $this->withHeaders($this->headers(['patient_vip.update'], (string) Str::uuid(), $actor))
            ->putJson(self::URL, [...$this->valid(), 'min_episodes' => 8, 'enabled' => false])
            ->assertOk();

        // Une seule ligne, remplacée sur place.
        $this->assertSame(1, PatientVipSetting::query()->count());
        $setting = PatientVipSetting::query()->firstOrFail();
        $this->assertSame(8, $setting->min_episodes);
        $this->assertFalse($setting->enabled);
        $this->assertSame($actor, $setting->external_updated_by_uuid);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'patient_vip.settings.update',
            'external_actor_uuid' => $actor,
        ]);
    }

    public function test_the_same_command_replayed_does_not_write_twice(): void
    {
        $key = (string) Str::uuid();
        $headers = fn () => $this->headers(['patient_vip.update'], $key);

        $this->withHeaders($headers())->putJson(self::URL, $this->valid())->assertOk();
        $before = AuditLog::query()->where('action', 'patient_vip.settings.update')->count();

        $this->withHeaders($headers())->putJson(self::URL, $this->valid())->assertOk();

        $this->assertSame($before, AuditLog::query()->where('action', 'patient_vip.settings.update')->count());
    }

    public function test_absurd_thresholds_are_refused(): void
    {
        $headers = fn () => $this->headers(['patient_vip.update'], (string) Str::uuid());

        foreach ([
            ['min_episodes' => 0],
            ['min_episodes' => 1001],
            ['window_months' => 0],
            ['window_months' => 121],
            ['min_amount' => -1],
            ['min_amount' => 'beaucoup'],
            ['enabled' => 'peut-être'],
        ] as $bad) {
            $this->withHeaders($headers())
                ->putJson(self::URL, [...$this->valid(), ...$bad])
                ->assertUnprocessable();
        }

        $this->assertSame(0, PatientVipSetting::query()->count());
    }

    public function test_the_vip_discount_is_saved_with_the_thresholds_and_removed_with_its_value(): void
    {
        $headers = fn () => $this->headers(['patient_vip.update'], (string) Str::uuid());

        $this->withHeaders($headers())
            ->putJson(self::URL, [...$this->valid(), 'discount_type' => 'PERCENT', 'discount_value' => 10])
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'PERCENT')
            ->assertJsonPath('data.discount_value', '10.00')
            ->assertJsonPath('data.discount', '10 %');

        $this->assertDatabaseHas('audit_logs', ['action' => 'patient_vip.settings.update']);

        // « Aucune remise » : le type et la valeur partent ensemble.
        $this->withHeaders($headers())
            ->putJson(self::URL, [...$this->valid(), 'discount_type' => null, 'discount_value' => null])
            ->assertOk()
            ->assertJsonPath('data.discount', null);

        $setting = PatientVipSetting::query()->sole();
        $this->assertNull($setting->discount_type);
        $this->assertNull($setting->discount_value);
    }

    public function test_an_incomplete_or_absurd_vip_discount_is_refused(): void
    {
        $headers = fn () => $this->headers(['patient_vip.update'], (string) Str::uuid());

        foreach ([
            ['discount_type' => 'PERCENT', 'discount_value' => 150],
            ['discount_type' => 'PERCENT', 'discount_value' => null],
            ['discount_type' => 'AMOUNT', 'discount_value' => 0],
            ['discount_type' => 'AMOUNT', 'discount_value' => '5000.125'],
            ['discount_type' => 'GRATUIT', 'discount_value' => 10],
        ] as $bad) {
            $this->withHeaders($headers())
                ->putJson(self::URL, [...$this->valid(), ...$bad])
                ->assertUnprocessable();
        }

        $this->assertSame(0, PatientVipSetting::query()->count());
    }

    public function test_a_preview_counts_without_writing_anything(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00'));
        $patient = Patient::create(['patient_number' => 'A-26-0001', 'first_name' => 'Jean', 'last_name' => 'Rakoto', 'birth_date' => '1990-05-12', 'sex' => 'M']);
        Episode::create(['patient_id' => $patient->id, 'visit_sequence' => 1, 'episode_number' => 'E1', 'status' => 'OPEN', 'priority' => 'NORMAL', 'administrative_status' => 'IN_CARE', 'started_at' => '2026-09-01 09:00:00']);

        // Aucun encaissement : même avec des seuils très bas côté argent, il faut les deux.
        $this->withHeaders($this->headers(['patient_vip.view'], (string) Str::uuid()))
            ->postJson(self::URL.'/preview', [...$this->valid(), 'min_episodes' => 1, 'min_amount' => 0])
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.patients_count', 1)
            ->assertJsonPath('data.rule', 'au moins 1 passage et 0 Ar encaissés sur les 12 derniers mois');

        $this->assertSame(0, PatientVipSetting::query()->count());
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['enabled' => true, 'min_episodes' => 5, 'min_amount' => 1000000, 'window_months' => 12];
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions, ?string $idempotencyKey = null, ?string $actorUuid = null): array
    {
        return array_filter([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }
}
