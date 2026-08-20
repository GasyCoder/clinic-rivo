<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseCount('audit_logs', 1);

        $log = AuditLog::first();

        $this->assertSame('login', $log->action);
        $this->assertSame('auth', $log->module);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(User::class, $log->entity_type);
        $this->assertSame($user->id, $log->entity_id);
        $this->assertNotNull($log->uuid);
        $this->assertNotNull($log->request_uuid);
        $this->assertNotNull($log->ip_address);
    }

    public function test_a_logout_is_audited_with_the_correct_actor_despite_the_session_being_destroyed_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseCount('audit_logs', 1);

        $log = AuditLog::first();

        $this->assertSame('logout', $log->action);
        $this->assertSame('auth', $log->module);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(User::class, $log->entity_type);
        $this->assertSame($user->id, $log->entity_id);
    }

    public function test_a_failed_login_attempt_is_not_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_login_then_logout_in_separate_requests_get_different_request_uuids(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->post('/logout');

        $logs = AuditLog::orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertNotSame($logs[0]->request_uuid, $logs[1]->request_uuid);
    }
}
