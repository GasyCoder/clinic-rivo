<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabAntibiogram;
use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use App\Models\LabResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/** ADR-213 — la paillasse : saisie, terminer, valider, renvoyer, microbiologie. */
class LaboratoryWorkbenchTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    public function test_a_numeric_result_is_flagged_against_its_reference_and_suggests_its_reading(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC', 'unit' => 'g/L', 'reference_general' => '0,70 - 1,10']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $glycemie);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '1,45']],
        ])->assertSessionHasNoErrors();

        $result = LabResult::query()->sole();
        $this->assertSame('1.45', $result->value);
        $this->assertSame('HIGH', $result->range_flag);
        $this->assertSame('PATHOLOGICAL', $result->interpretation);
        $this->assertSame(LabItemStatus::InProgress, $item->fresh()->currentStatus());
        // Un brouillon n'est pas un résultat rendu.
        $this->assertNull($item->fresh()->resulted_at);
    }

    public function test_a_value_that_is_not_a_number_is_refused(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $glycemie);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => 'élevée']],
        ])->assertSessionHasErrors('results.0.value');

        $this->assertSame(0, LabResult::query()->count());
    }

    public function test_complete_then_validate_renders_the_result_to_prescribers(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC', 'unit' => 'g/L']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $glycemie);

        // Rien de saisi : rien à rendre.
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasErrors('item');

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '0.95']],
        ]);
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(LabItemStatus::Completed, $item->currentStatus());
        $this->assertNotNull($item->resulted_at);
        $this->assertStringContainsString('0.95', (string) $item->result_value);

        // Terminée : la saisie est fermée.
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '2']],
        ])->assertSessionHasErrors('item');

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/validate")->assertSessionHasNoErrors();
        $this->assertSame(LabItemStatus::Validated, $item->fresh()->currentStatus());
        $this->assertSame($technician->id, $item->fresh()->validated_by);
    }

    public function test_a_completed_analysis_goes_back_to_the_bench_with_a_reason(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs, [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'result_value' => 'Normal',
        ]);

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Prélèvement hémolysé'])->assertSessionHasNoErrors();

        $this->assertSame(LabItemStatus::ToRedo, $item->fresh()->currentStatus());
    }

    public function test_validation_needs_its_own_permission(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs, [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'result_value' => 'Normal',
        ]);

        $this->actingAs($this->userWithRole('MEDICINE'))->post("/laboratory/items/{$item->uuid}/validate")->assertForbidden();
        $this->assertSame(LabItemStatus::Completed, $item->fresh()->currentStatus());
    }

    public function test_a_cancelled_request_is_no_longer_worked(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $definition = $this->definition($nfs, ['code' => 'HB', 'designation' => 'Hémoglobine']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician, cancelled: true), $nfs);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '13']],
        ])->assertSessionHasErrors('item');
    }

    public function test_a_culture_with_growth_opens_an_antibiogram_per_germ(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $ecbu = $this->prestation($technician, 'LAB-ECBU', 'ECBU');
        $culture = $this->definition($ecbu, ['code' => 'CULT', 'designation' => 'Culture', 'entry_mode' => 'CULTURE']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $ecbu);

        $family = LabBacteriumFamily::query()->create(['name' => 'Entérobactéries', 'is_active' => true]);
        $germ = LabBacterium::query()->create(['family_id' => $family->id, 'name' => 'Escherichia coli', 'is_active' => true]);
        $amox = LabAntibiotic::query()->create(['family_id' => $family->id, 'name' => 'Amoxicilline', 'is_active' => true]);

        // Présence de germe sans germe nommé : on ne rend pas.
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $culture->uuid, 'value' => 'GROWTH', 'selections' => ['bacteria' => []]]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasErrors('item');

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $culture->uuid, 'value' => 'GROWTH', 'selections' => ['bacteria' => [$germ->uuid]]]],
        ])->assertSessionHasNoErrors();

        $antibiogram = LabAntibiogram::query()->sole();
        $this->assertSame($germ->id, $antibiogram->bacterium_id);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/antibiograms/{$antibiogram->uuid}", [
            'lines' => [['antibiotic_uuid' => $amox->uuid, 'interpretation' => 'R', 'measure' => 9]],
            'notes' => 'BLSE suspectée',
        ])->assertSessionHasNoErrors();
        $this->assertSame('R', $antibiogram->results()->sole()->interpretation);

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasNoErrors();
        $this->assertStringContainsString('Escherichia coli', (string) $item->fresh()->result_value);
    }

    public function test_nugent_is_scored_and_read(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $fv = $this->prestation($technician, 'LAB-FV', 'Frottis vaginal');
        $nugent = $this->definition($fv, ['code' => 'NUG', 'designation' => 'Score de Nugent', 'entry_mode' => 'NUGENT']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $fv);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $nugent->uuid, 'selections' => ['lactobacilli' => 4, 'gardnerella' => 3, 'mobiluncus' => 1]]],
        ])->assertSessionHasNoErrors();

        $result = LabResult::query()->sole();
        $this->assertSame('8', $result->value);
        $this->assertSame('PATHOLOGICAL', $result->interpretation);
    }

    public function test_a_critical_result_is_flagged_by_hand_and_traced(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $glycemie);
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '4.2']],
        ]);
        $result = LabResult::query()->sole();

        $this->actingAs($this->userWithRole('MEDICINE'))->post("/laboratory/results/{$result->uuid}/critical", ['critical' => true])->assertForbidden();
        $this->actingAs($technician)->post("/laboratory/results/{$result->uuid}/critical", ['critical' => true])->assertSessionHasNoErrors();

        $result->refresh();
        $this->assertTrue($result->is_critical);
        $this->assertSame($technician->id, $result->critical_flagged_by);
    }

    public function test_the_request_page_and_its_print_sheet_render(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $this->definition($nfs, ['code' => 'HB', 'designation' => 'Hémoglobine', 'result_type' => 'NUMERIC', 'unit' => 'g/dL']);
        $request = $this->labRequest($episode, $orientation, $technician);
        $this->requestItem($request, $nfs);

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Show', false)
                ->count('items', 1)
                ->where('items.0.nodes.0.entry_mode', 'NUMERIC')
                ->where('can.enter', true)
                ->where('can.validate', true));

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/impression")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Laboratory/ResultsPrint', false));
    }
}
