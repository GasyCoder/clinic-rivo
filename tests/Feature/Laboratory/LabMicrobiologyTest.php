<?php

namespace Tests\Feature\Laboratory;

use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/** ADR-213 — le référentiel des germes et des antibiotiques, propre au site. */
class LabMicrobiologyTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    public function test_the_laboratory_manages_families_germs_and_antibiotics(): void
    {
        $technician = $this->userWithRole('LABORATORY');

        $this->actingAs($technician)->post('/laboratory/microbiologie/family', ['name' => 'Staphylocoques'])->assertSessionHasNoErrors();
        $family = LabBacteriumFamily::query()->sole();

        $this->actingAs($technician)->post('/laboratory/microbiologie/bacterium', ['name' => 'Staphylococcus aureus', 'family_uuid' => $family->uuid])->assertSessionHasNoErrors();
        $this->actingAs($technician)->post('/laboratory/microbiologie/antibiotic', ['name' => 'Oxacilline', 'family_uuid' => $family->uuid])->assertSessionHasNoErrors();

        // Un nom ne se porte pas deux fois dans la même famille.
        $this->actingAs($technician)->post('/laboratory/microbiologie/bacterium', ['name' => 'staphylococcus  AUREUS', 'family_uuid' => $family->uuid])->assertSessionHasErrors();
        $this->assertSame(1, LabBacterium::query()->count());

        $germ = LabBacterium::query()->sole();
        $this->actingAs($technician)->delete("/laboratory/microbiologie/bacterium/{$germ->uuid}", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($technician)->delete("/laboratory/microbiologie/bacterium/{$germ->uuid}", ['reason' => 'Doublon'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($germ);

        $this->actingAs($technician)->post("/laboratory/microbiologie/bacterium/{$germ->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($germ);

        $this->actingAs($technician)->get('/laboratory/microbiologie')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Microbiology', false)
                ->count('families', 1)
                ->count('families.0.bacteria', 1)
                ->count('families.0.antibiotics', 1));
    }

    public function test_the_starter_reference_is_imported_once(): void
    {
        $technician = $this->userWithRole('LABORATORY');

        $this->actingAs($technician)->post('/laboratory/microbiologie/referentiel-de-depart')->assertSessionHasNoErrors();
        $families = LabBacteriumFamily::query()->count();
        $bacteria = LabBacterium::query()->count();
        $antibiotics = LabAntibiotic::query()->count();
        $this->assertGreaterThan(0, $families);
        $this->assertGreaterThan(0, $bacteria);

        $this->actingAs($technician)->post('/laboratory/microbiologie/referentiel-de-depart');
        $this->assertSame($families, LabBacteriumFamily::query()->count());
        $this->assertSame($bacteria, LabBacterium::query()->count());
        $this->assertSame($antibiotics, LabAntibiotic::query()->count());
    }

    public function test_the_reference_is_guarded(): void
    {
        $reception = $this->userWithRole('RECEPTION');

        $this->actingAs($reception)->get('/laboratory/microbiologie')->assertForbidden();
        $this->actingAs($reception)->post('/laboratory/microbiologie/family', ['name' => 'X'])->assertForbidden();
    }
}
