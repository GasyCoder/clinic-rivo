<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabAntibiogram;
use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use App\Models\LabResult;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/** ADR-213/216 — la paillasse : saisie, envoi au médecin (qui valide), renvoyer, microbiologie. */
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

    public function test_sending_renders_and_validates_the_result_for_the_prescriber(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        $doctor = $this->userWithRole('MEDICINE');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC', 'unit' => 'g/L']);
        $request = $this->labRequest($episode, $orientation, $doctor);
        $item = $this->requestItem($request, $glycemie);
        $send = fn () => $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ]);

        // Rien de saisi : rien à envoyer.
        $send()->assertSessionHasErrors('items');

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '0.95']],
        ]);
        $send()->assertSessionHasNoErrors();

        $item->refresh();
        // ADR-216 — envoyer valide : il n'y a plus de biologiste distinct.
        $this->assertSame(LabItemStatus::Validated, $item->currentStatus());
        $this->assertNotNull($item->resulted_at);
        $this->assertNotNull($item->sent_at);
        $this->assertSame($technician->id, $item->validated_by);
        $this->assertStringContainsString('0.95', (string) $item->result_value);
        $this->assertSame($doctor->id, $request->fresh()->results_recipient_id);

        // Envoyée : la saisie est fermée, et on n'envoie pas deux fois.
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '2']],
        ])->assertSessionHasErrors('item');
        $send()->assertSessionHasErrors('items');
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

    /**
     * Amendement ADR-216 du 2026-09-29 — « Renvoyer à refaire » a son propre droit,
     * réglé depuis le portail : sans lui, le serveur refuse et l'écran le dit.
     */
    public function test_sending_back_needs_its_own_permission_even_for_the_technician(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $item = $this->requestItem($request, $nfs, [
            'status' => LabItemStatus::Validated, 'resulted_at' => now(), 'sent_at' => now(),
            'validated_at' => now(), 'result_value' => 'Normal',
        ]);

        $this->assertTrue($technician->can('laboratory_results.return'), 'Le socle LABORATORY porte le droit.');
        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.return', true));

        $technician->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'laboratory_results.return')->value('id') => ['effect' => 'deny'],
        ]);
        $technician = $technician->fresh();

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.return', false));
        // Saisir et envoyer ne suffisent plus : le droit se règle seul.
        $this->assertTrue($technician->can('laboratory_results.create'));
        $this->assertTrue($technician->can('laboratory_results.validate'));
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur à contrôler'])
            ->assertForbidden();
        $this->assertSame(LabItemStatus::Validated, $item->fresh()->currentStatus());

        // Accordé seul, à un compte qui ne saisit pas, il suffit à reprendre l'analyse.
        $doctor = $this->userWithRole('MEDICINE');
        $doctor->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'laboratory_results.view')->value('id') => ['effect' => 'allow'],
            Permission::query()->where('name', 'laboratory_results.return')->value('id') => ['effect' => 'allow'],
        ]);
        $this->actingAs($doctor->fresh())->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur à contrôler'])
            ->assertSessionHasNoErrors();
        $this->assertSame(LabItemStatus::ToRedo, $item->fresh()->currentStatus());
    }

    public function test_sending_needs_its_own_permission(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $item = $this->requestItem($request, $nfs, [
            'status' => LabItemStatus::Completed, 'resulted_at' => now(), 'result_value' => 'Normal',
        ]);

        $this->actingAs($this->userWithRole('MEDICINE'))->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'to_nobody' => true,
        ])->assertForbidden();
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
        $request = $this->labRequest($episode, $orientation, $technician);
        $item = $this->requestItem($request, $ecbu);
        $send = fn () => $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", ['items' => [$item->uuid], 'to_nobody' => true]);

        $family = LabBacteriumFamily::query()->create(['name' => 'Entérobactéries', 'is_active' => true]);
        $germ = LabBacterium::query()->create(['family_id' => $family->id, 'name' => 'Escherichia coli', 'is_active' => true]);
        $amox = LabAntibiotic::query()->create(['family_id' => $family->id, 'name' => 'Amoxicilline', 'is_active' => true]);

        // Présence de germe sans germe nommé : on ne rend pas.
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $culture->uuid, 'value' => 'GROWTH', 'selections' => ['bacteria' => []]]],
        ])->assertSessionHasNoErrors();
        $send()->assertSessionHasErrors('items');

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

        $send()->assertSessionHasNoErrors();
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
                ->where('can.send', true));

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/impression")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Laboratory/ResultsPrint', false));
    }
}
