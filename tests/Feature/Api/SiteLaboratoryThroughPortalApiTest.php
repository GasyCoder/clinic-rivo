<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\KeepPhysicalActsAtSite;
use App\Models\AuditLog;
use App\Models\LabBacteriumFamily;
use App\Models\LabRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-215 — le Super Admin du portail lit tout le Laboratoire d'un site et en
 * gère les référentiels, par l'API du site : mêmes routes, droits et actions
 * que /laboratory. Les gestes cliniques restent au laboratoire du site.
 */
class SiteLaboratoryThroughPortalApiTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    private const ACTOR_UUID = '8a1c2d3e-4f50-4617-8293-a4b5c6d7e8f9';

    private const ALL = [
        'laboratory_results.view', 'laboratory_results.create', 'laboratory_results.validate',
        'laboratory_results.flag_critical', 'laboratory_orders.receive', 'laboratory_orders.send_out',
        'laboratory_samples.create', 'laboratory_samples.update', 'lab_microbiology.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);

        $this->seedRoles();
    }

    public function test_the_portal_reads_the_laboratory_screens_of_the_site_as_data(): void
    {
        $this->withHeaders($this->headers(['laboratory_results.view']))
            ->getJson('/api/v1/super-admin/site-laboratory')
            ->assertOk()
            ->assertJsonPath('component', 'Laboratory/Index')
            ->assertJsonMissingPath('props.auth');

        $this->withHeaders($this->headers(['laboratory_results.view']))
            ->getJson('/api/v1/super-admin/site-laboratory/paillasse')
            ->assertOk()
            ->assertJsonPath('component', 'Laboratory/Worklist');
    }

    public function test_each_screen_keeps_its_own_permission(): void
    {
        $this->withHeaders($this->headers(['laboratory_results.view']))
            ->getJson('/api/v1/super-admin/site-laboratory/microbiologie')
            ->assertForbidden();

        $this->withHeaders($this->headers(['laboratory_results.view']))
            ->getJson('/api/v1/super-admin/site-laboratory/rapports')
            ->assertForbidden();

        $this->withHeaders([...$this->headers(['laboratory_results.view']), 'Authorization' => 'Bearer wrong'])
            ->getJson('/api/v1/super-admin/site-laboratory')
            ->assertUnauthorized();
    }

    /** Une demande s'ouvre en lecture : aucun geste clinique n'est proposé, même avec tous les droits. */
    public function test_a_request_opens_read_only_on_the_portal(): void
    {
        $request = $this->pendingRequest(received: true);

        $this->withHeaders($this->headers(self::ALL))
            ->getJson("/api/v1/super-admin/site-laboratory/requests/{$request->uuid}")
            ->assertOk()
            ->assertJsonPath('component', 'Laboratory/Show')
            ->assertJsonPath('props.can.site_only', true)
            ->assertJsonPath('props.can.enter', false)
            ->assertJsonPath('props.can.send', false)
            ->assertJsonPath('props.can.receive', false)
            ->assertJsonPath('props.can.sample', false)
            ->assertJsonPath('props.can.send_out', false)
            ->assertJsonPath('props.can.history', true)
            ->assertJsonPath('props.can.microbiology', true);
    }

    /** Réceptionner, saisir, envoyer au médecin : au laboratoire du site, même avec le droit. */
    public function test_clinical_gestures_stay_at_the_site_even_with_the_permission(): void
    {
        $waiting = $this->pendingRequest(received: false);

        $this->withHeaders($this->writeHeaders(self::ALL))
            ->postJson("/api/v1/super-admin/site-laboratory/requests/{$waiting->uuid}/receive", ['samples' => []])
            ->assertForbidden()
            ->assertJsonPath('message', KeepPhysicalActsAtSite::LABORATORY_MESSAGE);

        $this->assertNull($waiting->fresh()->received_at, 'rien n’est reçu depuis le portail');

        $received = $this->pendingRequest(received: true);
        $item = $received->items()->sole();

        $this->withHeaders($this->writeHeaders(self::ALL))
            ->putJson("/api/v1/super-admin/site-laboratory/items/{$item->uuid}/results", ['entries' => []])
            ->assertForbidden();

        // ADR-216 — envoyer au médecin (qui valide) reste un geste du site.
        $this->withHeaders($this->writeHeaders(self::ALL))
            ->postJson("/api/v1/super-admin/site-laboratory/requests/{$received->uuid}/send", ['items' => [$item->uuid], 'to_nobody' => true])
            ->assertForbidden()
            ->assertJsonPath('message', KeepPhysicalActsAtSite::LABORATORY_MESSAGE);
        // « Terminer » l'analyse aussi (amendement ADR-216 du 2026-09-29).
        $this->withHeaders($this->writeHeaders(self::ALL))
            ->postJson("/api/v1/super-admin/site-laboratory/items/{$item->uuid}/complete")
            ->assertForbidden()
            ->assertJsonPath('message', KeepPhysicalActsAtSite::LABORATORY_MESSAGE);

        $this->assertNull($item->fresh()->sent_at, 'rien n’est envoyé depuis le portail');
    }

    public function test_the_same_request_stays_workable_for_the_site_technician(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        $request = $this->pendingRequest(received: false, actor: $technician);

        $this->withoutVite()->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.site_only', false)->where('can.receive', true));

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($request->fresh()->received_at);
    }

    public function test_a_reference_is_managed_from_the_portal_and_signed_by_the_super_admin(): void
    {
        $users = User::query()->count();

        $this->withHeaders($this->writeHeaders(['lab_microbiology.view', 'lab_microbiology.create']))
            ->postJson('/api/v1/super-admin/site-laboratory/microbiologie/family', ['name' => 'Entérobactéries'])
            ->assertOk()
            ->assertJsonPath('redirect', fn ($redirect) => is_string($redirect));

        $family = LabBacteriumFamily::query()->sole();
        $this->assertNull($family->created_by, 'le Super Admin n’a pas de compte local');

        $audit = AuditLog::query()->where('entity_type', $family->getMorphClass())->where('entity_id', $family->id)->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);
        $this->assertSame('Direction centrale', $audit->external_actor_name);

        $this->assertSame($users, User::query()->count(), 'le Super Admin distant n’est jamais enregistré sur le site');
    }

    private function pendingRequest(bool $received, ?User $actor = null): LabRequest
    {
        $actor ??= $this->userWithRole('MEDICINE');
        [$episode, $orientation] = $this->episodeWithLabOrientation($actor);
        $request = $this->labRequest($episode, $orientation, $actor, received: $received);
        $this->requestItem($request, $this->prestation($actor, 'LAB-'.Str::upper(Str::random(6))));

        return $request;
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => self::ACTOR_UUID,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }

    /** @param array<int, string> $permissions */
    private function writeHeaders(array $permissions): array
    {
        return [...$this->headers($permissions), 'Idempotency-Key' => (string) Str::uuid()];
    }
}
