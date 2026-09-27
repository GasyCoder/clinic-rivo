<?php

namespace Tests\Feature\Api;

use App\Models\HospitalBed;
use App\Models\HospitalRoom;
use App\Models\HospitalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Hospitalization\Concerns\AdmitsHospitalizedPatients;
use Tests\TestCase;

/**
 * ADR-164 — services, chambres et lits d'un site, réglés depuis le portail par
 * l'API du site : réautorisés localement, audités avec l'identité centrale, et
 * jamais au prix d'un patient sans lit.
 */
class HospitalBedApiTest extends TestCase
{
    use AdmitsHospitalizedPatients, RefreshDatabase;

    private const URL = '/api/v1/super-admin/hospital-beds';

    private const ALL = ['hospital_beds.view', 'hospital_beds.create', 'hospital_beds.update', 'hospital_beds.archive', 'hospital_beds.restore'];

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

    public function test_each_gesture_needs_its_own_permission(): void
    {
        $this->withHeaders($this->headers([]))->getJson(self::URL)->assertForbidden();
        $this->withHeaders($this->headers(['hospital_beds.view']))
            ->postJson(self::URL.'/services', ['name' => 'Médecine interne', 'care_level' => 'STANDARD'])
            ->assertForbidden();

        $this->assertSame(0, HospitalService::query()->count());
    }

    public function test_a_room_is_created_with_its_number_of_beds_and_audited_with_the_central_actor(): void
    {
        $actor = (string) Str::uuid();

        $this->withHeaders($this->headers(self::ALL, $actor))
            ->postJson(self::URL.'/services', ['name' => 'Réanimation', 'care_level' => 'INTENSIVE'])
            ->assertCreated();
        $service = HospitalService::query()->sole();

        $this->withHeaders($this->headers(self::ALL, $actor))
            ->postJson(self::URL."/services/{$service->uuid}/rooms", ['name' => 'Box', 'bed_count' => 3])
            ->assertCreated()
            ->assertJsonPath('message', 'Chambre « Box » créée avec 3 lits.');

        $this->withHeaders($this->headers(self::ALL))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.summary.beds', 3)
            ->assertJsonPath('meta.summary.free', 3)
            ->assertJsonPath('data.0.care_level_label', 'Réanimation')
            ->assertJsonPath('data.0.rooms.0.beds.2.label', 'Lit 3');

        $this->assertDatabaseHas('audit_logs', ['entity_type' => (new HospitalRoom)->getMorphClass(), 'external_actor_uuid' => $actor]);
    }

    public function test_added_beds_follow_the_numbers_already_taken_up_to_the_room_limit(): void
    {
        [$room] = $this->room(2);
        HospitalBed::query()->where('label', 'Lit 2')->first()->delete();

        $this->withHeaders($this->headers(self::ALL))
            ->postJson(self::URL."/rooms/{$room->uuid}/beds", ['count' => 2])
            ->assertOk();

        // « Lit 2 » archivé garde son nom : les nouveaux lits prennent la suite.
        $this->assertSame(['Lit 1', 'Lit 3', 'Lit 4'], $room->beds()->orderBy('id')->pluck('label')->all());

        $this->withHeaders($this->headers(self::ALL))
            ->postJson(self::URL."/rooms/{$room->uuid}/beds", ['count' => 28])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');
    }

    public function test_names_compare_without_accents_and_an_archived_name_is_restored_not_recreated(): void
    {
        $headers = fn () => $this->headers(self::ALL);
        $this->withHeaders($headers())->postJson(self::URL.'/services', ['name' => 'Médecine interne', 'care_level' => 'STANDARD'])->assertCreated();

        $this->withHeaders($headers())->postJson(self::URL.'/services', ['name' => '  medecine  INTERNE ', 'care_level' => 'STANDARD'])
            ->assertJsonValidationErrors(['name' => 'Un service porte déjà ce nom.']);

        $service = HospitalService::query()->sole();
        $this->withHeaders($headers())->deleteJson(self::URL."/services/{$service->uuid}", ['reason' => 'Service fermé'])->assertOk();
        $this->withHeaders($headers())->postJson(self::URL.'/services', ['name' => 'Médecine interne', 'care_level' => 'STANDARD'])
            ->assertJsonValidationErrors(['name' => 'Un service archivé porte ce nom : restaurez-le au lieu d’en créer un nouveau.']);

        $this->withHeaders($headers())->postJson(self::URL."/services/{$service->uuid}/restore")->assertOk();
        $this->assertFalse($service->fresh()->trashed());
    }

    public function test_an_occupied_bed_keeps_its_room_and_service_in_use(): void
    {
        [$room, $beds] = $this->room(2);
        $this->occupy($beds[0]);
        $headers = fn () => $this->headers(self::ALL);

        $this->withHeaders($headers())->postJson(self::URL."/beds/{$beds[0]->uuid}/out-of-service", ['reason' => 'Sommier cassé'])
            ->assertJsonValidationErrors('bed');
        $this->withHeaders($headers())->deleteJson(self::URL."/beds/{$beds[0]->uuid}", ['reason' => 'Lit retiré'])
            ->assertJsonValidationErrors('bed');
        $this->withHeaders($headers())->deleteJson(self::URL."/rooms/{$room->uuid}", ['reason' => 'Travaux prévus'])
            ->assertJsonValidationErrors('room');
        $this->withHeaders($headers())->deleteJson(self::URL."/services/{$room->service->uuid}", ['reason' => 'Service fermé'])
            ->assertJsonValidationErrors('service');

        // Le lit libre de la même chambre, lui, se met hors service puis revient.
        $this->withHeaders($headers())->postJson(self::URL."/beds/{$beds[1]->uuid}/out-of-service", ['reason' => 'Sommier cassé'])->assertOk();
        $this->withHeaders($headers())->getJson(self::URL)
            ->assertJsonPath('meta.summary.occupied', 1)
            ->assertJsonPath('meta.summary.out_of_service', 1)
            ->assertJsonPath('meta.summary.free', 0)
            ->assertJsonPath('data.0.rooms.0.beds.1.out_of_service_by', 'Direction centrale');
        $this->withHeaders($headers())->postJson(self::URL."/beds/{$beds[1]->uuid}/in-service")->assertOk();
        $this->assertNull($beds[1]->fresh()->out_of_service_at);
    }

    public function test_the_portal_sees_occupancy_but_never_the_patient_name(): void
    {
        [, $beds] = $this->room(1);
        $this->occupy($beds[0]);

        $response = $this->withHeaders($this->headers(self::ALL))->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.0.rooms.0.beds.0.state', 'OCCUPIED');
        $this->assertNotNull($response->json('data.0.rooms.0.beds.0.occupant.episode_number'));
        $this->assertArrayNotHasKey('patient', $response->json('data.0.rooms.0.beds.0.occupant'));
        $this->assertStringNotContainsString('RAKOTO', $response->getContent());
    }

    public function test_an_archived_room_hides_its_beds_and_restores_them_as_they_were(): void
    {
        [$room] = $this->room(2);
        $headers = fn () => $this->headers(self::ALL);

        $this->withHeaders($headers())->deleteJson(self::URL."/rooms/{$room->uuid}", ['reason' => 'Travaux'])->assertOk();
        $this->withHeaders($headers())->getJson(self::URL)->assertJsonPath('meta.summary.beds', 0);

        $this->withHeaders($headers())->deleteJson(self::URL."/services/{$room->service->uuid}", ['reason' => 'Service fermé'])->assertOk();
        $this->withHeaders($headers())->postJson(self::URL."/rooms/{$room->uuid}/restore")
            ->assertJsonValidationErrors(['room' => 'Son service est archivé : restaurez d’abord le service.']);

        $this->withHeaders($headers())->postJson(self::URL."/services/{$room->service->uuid}/restore")->assertOk();
        $this->withHeaders($headers())->postJson(self::URL."/rooms/{$room->uuid}/restore")->assertOk();
        $this->withHeaders($headers())->getJson(self::URL)->assertJsonPath('meta.summary.beds', 2);
    }

    /** @return array{0: HospitalRoom, 1: list<HospitalBed>} */
    private function room(int $beds): array
    {
        $service = HospitalService::query()->create(['name' => 'Médecine interne', 'care_level' => 'STANDARD']);
        $room = HospitalRoom::query()->create(['hospital_service_id' => $service->id, 'name' => 'Chambre 12']);
        $models = collect(range(1, $beds))->map(fn (int $n) => HospitalBed::query()->create(['hospital_room_id' => $room->id, 'label' => "Lit {$n}"]))->all();

        return [$room->fresh(), $models];
    }

    /** Un patient réellement admis, installé dans ce lit. */
    private function occupy(HospitalBed $bed): void
    {
        $stay = $this->admitted($this->hospitalDoctor());
        $stay->update(['hospital_bed_id' => $bed->id]);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions, ?string $actorUuid = null): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
