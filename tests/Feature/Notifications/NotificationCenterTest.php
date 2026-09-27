<?php

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NewEmployeesAwaitingAccess;
use App\Notifications\StaffAccessReady;
use App\Services\Notifications\NotificationCenter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-197 — la boîte de notifications d'un compte : non lues, toutes, archivées ;
 * chacun ne touche que les siennes ; ouvrir marque lu et ne suit qu'un lien interne.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->user = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_page_lists_all_unread_and_archived_with_their_counts(): void
    {
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Soa Rakoto', 'Hery Rabe'], 'Direction', 7));
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Vola Rabe'], 'Direction', 14));
        $archived = $this->notification();
        $archived->forceFill(['archived_at' => now(), 'read_at' => now()])->save();
        $read = UserNotification::query()->for($this->user)->whereNull('archived_at')->oldest()->first();
        $read->forceFill(['read_at' => now()])->save();

        $this->actingAs($this->user)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->where('filters.status', 'all')
                ->where('counts', ['unread' => 0, 'all' => 1, 'archived' => 1])
                ->has('inbox.data', 1)
                ->where('inbox.data.0.category_label', 'Accès du personnel')
                ->where('inbox.data.0.read', true));
    }

    public function test_filters_by_status_category_and_accent_insensitive_search(): void
    {
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Zéphyr Andrianina'], 'Direction', 14));
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Soa Rakoto'], 'Direction', 14));

        $this->actingAs($this->user)->get('/notifications?status=unread')
            ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'unread')->has('inbox.data', 2)->where('counts.unread', 2));

        $this->actingAs($this->user)->get('/notifications?q=zephyr')
            ->assertInertia(fn (Assert $page) => $page->has('inbox.data', 1)->where('filters.q', 'zephyr'));

        $this->actingAs($this->user)->get('/notifications?category=staff_access')
            ->assertInertia(fn (Assert $page) => $page->has('inbox.data', 2)->where('filters.category', 'staff_access'));

        // Une valeur inconnue ne filtre rien.
        $this->actingAs($this->user)->get('/notifications?status=nimporte&category=autre')
            ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'all')->where('filters.category', null)->has('inbox.data', 2));
    }

    public function test_the_bell_reads_the_unread_count_and_the_latest(): void
    {
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Soa Rakoto'], 'Direction', 14));

        $this->actingAs($this->user)->getJson('/notifications/resume')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('items.0.title', 'Un accès créé pour le personnel')
            ->assertJsonPath('items.0.read', false);

        $this->actingAs($this->user)->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page->where('inbox.data.0.icon', 'key-round')->where('notifications.unread', 1)->where('auth.user.id', $this->user->id)->etc());
    }

    public function test_read_unread_archive_and_read_all_touch_only_ones_own(): void
    {
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Soa Rakoto'], 'Direction', 14));
        $this->user->notify(new StaffAccessReady((string) Str::uuid(), ['Hery Rabe'], 'Direction', 14));
        $other = User::factory()->create(['role_id' => $this->user->role_id]);
        $other->notify(new StaffAccessReady((string) Str::uuid(), ['Vola Rabe'], 'Direction', 14));

        $mine = UserNotification::query()->for($this->user)->pluck('id')->all();
        $theirs = UserNotification::query()->for($other)->value('id');

        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => [$mine[0], $theirs], 'action' => 'read'])
            ->assertOk()->assertJsonPath('changed', 1)->assertJsonPath('unread', 1);
        $this->assertNull(UserNotification::query()->find($theirs)->read_at, 'jamais celle d’un autre compte');

        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => [$mine[0]], 'action' => 'unread'])->assertJsonPath('unread', 2);
        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => [$mine[1]], 'action' => 'archive'])->assertJsonPath('unread', 1);
        $this->assertNotNull(UserNotification::query()->find($mine[1])->archived_at);
        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => [$mine[1]], 'action' => 'unarchive'])->assertOk();
        $this->assertNull(UserNotification::query()->find($mine[1])->archived_at);

        $this->actingAs($this->user)->postJson('/notifications/tout-lire')->assertJsonPath('unread', 0);
        $this->assertSame(0, UserNotification::query()->for($this->user)->whereNull('read_at')->count());
        $this->assertNull(UserNotification::query()->find($theirs)->read_at);

        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => ['pas-un-uuid'], 'action' => 'read'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/notifications/actions', ['ids' => [$mine[0]], 'action' => 'delete'])->assertUnprocessable();
    }

    public function test_opening_marks_read_and_follows_only_an_internal_link(): void
    {
        $uuid = (string) Str::uuid();
        $this->user->notify(new StaffAccessReady($uuid, ['Soa Rakoto'], 'Direction', 14));
        $notification = $this->notification();

        $this->actingAs($this->user)->get("/notifications/{$notification->id}/ouvrir")->assertRedirect("/administration/staff-access/{$uuid}");
        $this->assertNotNull($notification->fresh()->read_at);

        // Un lien extérieur glissé dans une notification n'est jamais suivi.
        $notification->forceFill(['data' => [...$notification->data, 'url' => 'https://ailleurs.test/piege']])->save();
        $this->actingAs($this->user)->get("/notifications/{$notification->id}/ouvrir")->assertRedirect(route('notifications.index'));
        $notification->forceFill(['data' => [...$notification->data, 'url' => '//ailleurs.test']])->save();
        $this->actingAs($this->user)->get("/notifications/{$notification->id}/ouvrir")->assertRedirect(route('notifications.index'));

        $stranger = User::factory()->create(['role_id' => $this->user->role_id]);
        $this->actingAs($stranger)->get("/notifications/{$notification->id}/ouvrir")->assertNotFound();
    }

    public function test_a_base_without_the_migration_says_so_instead_of_failing(): void
    {
        Schema::drop('notifications');

        $this->actingAs($this->user)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->where('unavailable', NotificationCenter::NOT_INSTALLED_MESSAGE)
                ->where('counts', ['unread' => 0, 'all' => 0, 'archived' => 0])
                ->has('inbox.data', 0));

        $this->actingAs($this->user)->getJson('/notifications/resume')
            ->assertOk()
            ->assertJson(['unread' => 0, 'items' => [], 'unavailable' => NotificationCenter::NOT_INSTALLED_MESSAGE]);
    }

    public function test_the_unread_count_is_shared_with_every_page(): void
    {
        $this->user->notify(new NewEmployeesAwaitingAccess('A', 'Ambondromamy', ['Soa Rakoto'], 1));

        $this->actingAs($this->user)->get('/profil')->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 1)->etc());
    }

    private function notification(): UserNotification
    {
        return UserNotification::query()->for($this->user)->latest()->firstOrFail();
    }
}
