<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_uuid_is_generated_automatically_on_creation(): void
    {
        $log = AuditLog::create(['action' => 'create']);

        $this->assertNotNull($log->uuid);
    }

    public function test_an_audit_log_cannot_be_updated(): void
    {
        $log = AuditLog::create(['action' => 'create']);

        $this->expectException(\LogicException::class);

        $log->update(['action' => 'update']);
    }

    public function test_an_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::create(['action' => 'create']);

        $this->expectException(\LogicException::class);

        $log->delete();
    }
}
