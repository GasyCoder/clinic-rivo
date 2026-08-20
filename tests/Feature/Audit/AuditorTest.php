<?php

namespace Tests\Feature\Audit;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Binds an explicit Request instance rather than relying on whatever
     * the test harness happens to have bound ambiently — makes the
     * request_uuid correlation assertions deterministic.
     */
    private function auditorForFakeRequest(?User $user = null): Auditor
    {
        $request = Request::create('/test');

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        $this->app->instance(Request::class, $request);

        return $this->app->make(Auditor::class);
    }

    public function test_multiple_entries_recorded_during_the_same_request_share_one_request_uuid(): void
    {
        $auditor = $this->auditorForFakeRequest(User::factory()->create());

        $first = $auditor->record('create', module: 'test');
        $second = $auditor->record('update', module: 'test');

        $this->assertNotNull($first->request_uuid);
        $this->assertSame($first->request_uuid, $second->request_uuid);
    }

    public function test_record_captures_old_and_new_values_and_a_reason(): void
    {
        $user = User::factory()->create();
        $auditor = $this->auditorForFakeRequest($user);

        $log = $auditor->record(
            'update',
            entity: $user,
            newValues: ['name' => 'New Name'],
            oldValues: ['name' => 'Old Name'],
            reason: 'Correction orthographe',
        );

        $this->assertSame(['name' => 'New Name'], $log->new_values);
        $this->assertSame(['name' => 'Old Name'], $log->old_values);
        $this->assertSame('Correction orthographe', $log->reason);
    }

    public function test_entity_uuid_is_captured_from_the_entity_when_it_has_one(): void
    {
        $user = User::factory()->create();
        $auditor = $this->auditorForFakeRequest($user);

        $log = $auditor->record('update', entity: $user);

        $this->assertSame($user->uuid, $log->entity_uuid);
    }

    public function test_entity_uuid_stays_null_when_the_entity_model_has_no_uuid_column(): void
    {
        // Role has no uuid column (nothing in the CDC lists roles as
        // API-exchangeable) — documents that entity_uuid degrades to null
        // instead of erroring when the entity model has no such attribute.
        $user = User::factory()->create();
        $auditor = $this->auditorForFakeRequest($user);

        $role = Role::query()->create(['code' => 'TEST_ROLE', 'name' => 'Test Role']);

        $log = $auditor->record('update', entity: $role);

        $this->assertNull($log->entity_uuid);
    }

    public function test_an_explicit_actor_overrides_the_request_user(): void
    {
        $requestUser = User::factory()->create();
        $explicitActor = User::factory()->create();

        $auditor = $this->auditorForFakeRequest($requestUser);

        $log = $auditor->record('logout', actor: $explicitActor);

        $this->assertSame($explicitActor->id, $log->user_id);
    }

    public function test_record_identifies_the_current_operational_site(): void
    {
        config()->set('rivo.site.code', 'MAMPIKONY');
        config()->set('rivo.site.name', 'Clinique Saint Georges — Mampikony');

        $log = $this->auditorForFakeRequest(User::factory()->create())
            ->record('payment.create', module: 'cash');

        $this->assertSame('MAMPIKONY', $log->site_code);
        $this->assertSame('Clinique Saint Georges — Mampikony', $log->site_name);
    }
}
