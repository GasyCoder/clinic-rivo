<?php

namespace Tests\Feature\Administration;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_database_seeding_never_creates_a_demo_login_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('allergen_references', [
            'code' => 'PENICILLINS',
            'name' => 'Pénicillines',
            'active' => true,
        ]);
        $this->assertDatabaseHas('allergen_references', [
            'code' => 'LATEX',
            'name' => 'Latex',
            'active' => true,
        ]);
    }
}
