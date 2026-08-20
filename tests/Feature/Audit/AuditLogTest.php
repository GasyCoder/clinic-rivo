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

    public function test_a_bulk_query_delete_is_also_refused(): void
    {
        // Distinct from the instance-level guard above: Model::deleting()
        // never fires for a bulk `Model::query()->delete()` call, so this
        // exercises the separate ImmutableBuilder guard, not the model
        // event one.
        AuditLog::create(['action' => 'create']);

        $this->expectException(\LogicException::class);

        AuditLog::query()->delete();
    }

    public function test_a_bulk_query_update_is_also_refused(): void
    {
        AuditLog::create(['action' => 'create']);

        $this->expectException(\LogicException::class);

        AuditLog::query()->update(['action' => 'tampered']);
    }

    public function test_bulk_delete_attempt_does_not_remove_the_row(): void
    {
        $log = AuditLog::create(['action' => 'create']);

        try {
            AuditLog::query()->delete();
        } catch (\LogicException) {
            // expected
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}
