<?php

namespace Tests\Feature\Administration;

use App\Models\MutualOrganization;
use Database\Seeders\DevelopmentMutualOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DevelopmentMutualOrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_only_real_organizations_idempotently_for_a_local_clinic(): void
    {
        config(['rivo.site.type' => 'clinic']);

        $this->seed(DevelopmentMutualOrganizationSeeder::class);
        $this->seed(DevelopmentMutualOrganizationSeeder::class);

        $this->assertDatabaseCount('mutual_organizations', count(DevelopmentMutualOrganizationSeeder::ORGANIZATIONS));
        $this->assertDatabaseHas('mutual_organizations', ['name' => 'ADEFI', 'active' => true]);
        $this->assertDatabaseHas('mutual_organizations', ['name' => 'Personnels Ghisbert', 'active' => true]);
        $this->assertDatabaseMissing('mutual_organizations', [
            'normalized_name' => MutualOrganization::normalize('Sans Mutuelle'),
        ]);
        $this->assertDatabaseMissing('mutual_organizations', [
            'normalized_name' => MutualOrganization::normalize('Avantage Personnel'),
        ]);
    }

    public function test_it_is_refused_on_the_central_admin_deployment(): void
    {
        config(['rivo.site.type' => 'admin']);

        $this->expectException(LogicException::class);

        $this->seed(DevelopmentMutualOrganizationSeeder::class);
    }
}
