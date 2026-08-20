<?php

namespace Tests\Feature\SoftDelete;

use App\Exceptions\ForceDeleteForbiddenException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\ProtectedTestWidget;
use Tests\Fixtures\Models\TestWidget;
use Tests\TestCase;

class SoftDeletableTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletesWithReason();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');

        parent::tearDown();
    }

    public function test_deleting_soft_deletes_and_records_who_and_why(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $widget = TestWidget::create(['name' => 'Widget']);
        $widget->delete_reason = 'Doublon confirmé';
        $widget->delete();

        $this->assertSoftDeleted($widget);

        // fresh() respects the SoftDeletingScope and would return null for
        // an already-trashed row — withTrashed() is required to re-fetch it.
        $widget = TestWidget::withTrashed()->find($widget->id);
        $this->assertSame($user->id, $widget->deleted_by);
        $this->assertSame('Doublon confirmé', $widget->delete_reason);

        $log = AuditLog::where('action', 'delete')->first();
        $this->assertNotNull($log);
        $this->assertSame(TestWidget::class, $log->entity_type);
        $this->assertSame($widget->id, $log->entity_id);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Doublon confirmé', $log->reason);
    }

    public function test_deleting_without_a_reason_still_soft_deletes(): void
    {
        $widget = TestWidget::create(['name' => 'Widget']);
        $widget->delete();

        $this->assertSoftDeleted($widget);
        $this->assertNull(TestWidget::withTrashed()->find($widget->id)->delete_reason);
    }

    public function test_restoring_clears_the_reason_and_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $widget = TestWidget::create(['name' => 'Widget']);
        $widget->delete_reason = 'Doublon confirmé';
        $widget->delete();

        $widget->restore();

        $fresh = $widget->fresh();
        $this->assertNull($fresh->deleted_at);
        $this->assertNull($fresh->delete_reason);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_type' => TestWidget::class,
            'entity_id' => $widget->id,
        ]);
    }

    public function test_force_delete_removes_the_row_and_is_audited_when_allowed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $widget = TestWidget::create(['name' => 'Widget']);
        $id = $widget->id;
        $widget->forceDelete();

        $this->assertDatabaseMissing('widgets', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'force_delete',
            'entity_type' => TestWidget::class,
            'entity_id' => $id,
        ]);
    }

    public function test_force_delete_is_refused_when_the_model_marks_itself_protected(): void
    {
        $widget = ProtectedTestWidget::create(['name' => 'Widget']);

        try {
            $widget->forceDelete();
            $this->fail('Expected ForceDeleteForbiddenException to be thrown.');
        } catch (ForceDeleteForbiddenException $e) {
            $this->assertStringContainsString('force_delete refusé', $e->getMessage());
        }

        $this->assertDatabaseHas('widgets', ['id' => $widget->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'force_delete']);
    }

    public function test_a_protected_model_can_still_be_soft_deleted_normally(): void
    {
        $widget = ProtectedTestWidget::create(['name' => 'Widget']);
        $widget->delete();

        $this->assertSoftDeleted($widget);
    }
}
