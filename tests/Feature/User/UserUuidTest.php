<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_receives_a_uuid_automatically(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $user->uuid,
        );
    }

    public function test_two_users_receive_different_uuids(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertNotSame($first->uuid, $second->uuid);
    }

    public function test_an_explicitly_set_uuid_is_not_overwritten(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';

        $user = User::factory()->create(['uuid' => $uuid]);

        $this->assertSame($uuid, $user->uuid);
    }

    public function test_uuid_must_be_unique_at_the_database_level(): void
    {
        $existing = User::factory()->create();

        $this->expectException(QueryException::class);

        User::factory()->create(['uuid' => $existing->uuid, 'email' => 'other@example.com']);
    }

    public function test_uuid_is_not_mass_assignable_via_unexpected_input(): void
    {
        // Guards against a form accidentally letting a client dictate its
        // own distributed identifier — 'uuid' must stay out of $fillable.
        $this->assertNotContains('uuid', (new User)->getFillable());
    }
}
