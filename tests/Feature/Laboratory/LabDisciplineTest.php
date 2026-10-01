<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabEntryMode;
use App\Models\AnalysisCatalog;
use App\Models\AuditLog;
use App\Models\LabDiscipline;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Laboratory\LabDisciplines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-238 — les disciplines du laboratoire en référentiel, et un mode de
 * saisie qui va toujours au type de résultat.
 */
class LabDisciplineTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    public function test_the_laboratory_creates_renames_and_lists_its_disciplines(): void
    {
        $actor = $this->userWithRole('LABORATORY');

        $this->actingAs($actor)->post('/laboratory/disciplines', ['name' => '  Hématologie  '])->assertRedirect();
        $discipline = LabDiscipline::query()->where('name', 'Hématologie')->firstOrFail();
        $this->assertSame('hematologie', $discipline->normalized_name);

        $prestation = $this->prestation($actor);
        $analysis = $this->definition($prestation, ['code' => 'HB', 'designation' => 'Hémoglobine', 'lab_discipline_id' => $discipline->id]);
        $this->assertSame('Hématologie', $analysis->fresh()->exam_category);

        $this->actingAs($actor)->put("/laboratory/disciplines/{$discipline->uuid}", ['name' => 'HEMATOLOGIE', 'display_order' => 5])->assertRedirect();
        $this->assertSame('HEMATOLOGIE', $analysis->fresh()->exam_category, 'La copie du nom suit le référentiel.');

        $this->actingAs($actor)->get('/laboratory/disciplines')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Disciplines')
                ->where('disciplines.0.name', 'HEMATOLOGIE')
                ->where('disciplines.0.analyses_count', 1)
                ->where('can.merge', true));
    }

    public function test_a_name_already_taken_is_refused_accents_and_case_ignored_archives_included(): void
    {
        $actor = $this->userWithRole('LABORATORY');
        $this->actingAs($actor)->post('/laboratory/disciplines', ['name' => 'Biochimie'])->assertRedirect();

        $this->actingAs($actor)->from('/laboratory/disciplines')
            ->post('/laboratory/disciplines', ['name' => 'BIOCHIMIE'])
            ->assertSessionHasErrors('name');

        $discipline = LabDiscipline::query()->firstOrFail();
        $this->actingAs($actor)->delete("/laboratory/disciplines/{$discipline->uuid}", ['reason' => 'Doublon'])->assertRedirect();

        $this->actingAs($actor)->from('/laboratory/disciplines')
            ->post('/laboratory/disciplines', ['name' => 'biochimie'])
            ->assertSessionHasErrors(['name' => 'La discipline « Biochimie » existe, archivée : restaurez-la plutôt que de la recréer.']);

        $this->actingAs($actor)->post("/laboratory/disciplines/{$discipline->uuid}/restore")->assertRedirect();
        $this->assertFalse($discipline->fresh()->trashed());
    }

    public function test_a_discipline_still_carried_by_analyses_is_not_archived_but_merged(): void
    {
        $actor = $this->userWithRole('LABORATORY');
        $prestation = $this->prestation($actor);
        $right = $this->definition($prestation, ['code' => 'GLY', 'designation' => 'Glycémie', 'exam_category' => 'BIOCHIMIE']);
        $typo = $this->definition($prestation, ['code' => 'URE', 'designation' => 'Urée', 'exam_category' => 'BIOCHIME']);

        $target = LabDiscipline::query()->where('name', 'BIOCHIMIE')->firstOrFail();
        $source = LabDiscipline::query()->where('name', 'BIOCHIME')->firstOrFail();
        $this->assertNotSame($target->id, $source->id, 'Une faute de frappe n’est jamais fusionnée d’office.');

        $this->actingAs($actor)->from('/laboratory/disciplines')
            ->delete("/laboratory/disciplines/{$source->uuid}", ['reason' => 'Faute de frappe'])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($source->fresh()->trashed());

        $this->actingAs($actor)->post("/laboratory/disciplines/{$source->uuid}/merge", ['target_uuid' => $target->uuid])->assertRedirect();

        $this->assertSame($target->id, $typo->fresh()->lab_discipline_id);
        $this->assertSame('BIOCHIMIE', $typo->fresh()->exam_category);
        $this->assertSame($target->id, $right->fresh()->lab_discipline_id);
        $this->assertTrue($source->fresh()->trashed());
        $this->assertSame('Fusionnée avec « BIOCHIMIE ».', $source->fresh()->delete_reason);
        $this->assertTrue(AuditLog::query()->where('action', 'lab_discipline.merge')->exists());
    }

    public function test_without_the_right_the_referential_is_refused(): void
    {
        $reception = $this->userWithRole('RECEPTION');

        $this->actingAs($reception)->get('/laboratory/disciplines')->assertForbidden();
        $this->actingAs($reception)->post('/laboratory/disciplines', ['name' => 'Sérologie'])->assertForbidden();
        $this->assertSame(0, LabDiscipline::query()->count());
    }

    public function test_a_sub_analysis_takes_the_discipline_of_its_group_and_follows_it(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $prestation = $this->prestation($actor);
        $hematology = LabDiscipline::query()->create(['name' => 'Hématologie', 'display_order' => 10, 'is_active' => true]);
        $biochemistry = LabDiscipline::query()->create(['name' => 'Biochimie', 'display_order' => 20, 'is_active' => true]);

        $this->actingAs($actor)->post('/administration/analyses', [
            ...$this->payload($prestation, 'NFS', 'PARENT', 'Numération'),
            'lab_discipline_uuid' => $hematology->uuid,
            'children' => [[
                'code' => 'GR', 'level' => 'CHILD', 'designation' => 'Globules rouges', 'result_type' => 'NUMERIC',
                'display_order' => 1, 'lab_discipline_uuid' => $biochemistry->uuid,
            ]],
        ])->assertRedirect();

        $group = AnalysisCatalog::query()->where('code', 'NFS')->firstOrFail();
        $child = AnalysisCatalog::query()->where('code', 'GR')->firstOrFail();
        $this->assertSame($hematology->id, $group->lab_discipline_id);
        $this->assertSame($hematology->id, $child->lab_discipline_id, 'Une sous-analyse ne choisit pas sa discipline.');

        $this->actingAs($actor)->put("/administration/analyses/{$group->uuid}", [
            ...$this->payload($prestation, 'NFS', 'PARENT', 'Numération'),
            'lab_discipline_uuid' => $biochemistry->uuid,
        ])->assertRedirect();

        $this->assertSame($biochemistry->id, $child->fresh()->lab_discipline_id);
        $this->assertSame('Biochimie', $child->fresh()->exam_category);
    }

    public function test_a_new_discipline_named_in_the_record_is_created_once_or_refused_without_the_right(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $prestation = $this->prestation($actor);

        $this->actingAs($actor)->post('/administration/analyses', [
            ...$this->payload($prestation, 'CRP', 'NORMAL', 'CRP'),
            'new_discipline_name' => 'Immunologie',
        ])->assertRedirect();
        $this->actingAs($actor)->post('/administration/analyses', [
            ...$this->payload($prestation, 'ASLO', 'NORMAL', 'ASLO'),
            'new_discipline_name' => 'IMMUNOLOGIE',
        ])->assertRedirect();

        $this->assertSame(1, LabDiscipline::query()->count(), 'Le même nom retrouve la discipline, jamais un doublon.');
        $this->assertSame(
            AnalysisCatalog::query()->where('code', 'CRP')->value('lab_discipline_id'),
            AnalysisCatalog::query()->where('code', 'ASLO')->value('lab_discipline_id'),
        );

        $role = Role::query()->where('code', 'ADMINISTRATION')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'lab_disciplines.create')->value('id'));
        Cache::forget(Permission::CACHE_KEY);

        $this->actingAs($actor->fresh())->from('/administration/analyses/create')->post('/administration/analyses', [
            ...$this->payload($prestation, 'TSH', 'NORMAL', 'TSH'),
            'new_discipline_name' => 'Hormonologie',
        ])->assertSessionHasErrors('new_discipline_name');
        $this->assertFalse(AnalysisCatalog::query()->where('code', 'TSH')->exists());
    }

    public function test_an_entry_mode_that_does_not_fit_the_result_type_is_refused(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $prestation = $this->prestation($actor);

        $this->actingAs($actor)->from('/administration/analyses/create')->post('/administration/analyses', [
            ...$this->payload($prestation, 'GLY', 'NORMAL', 'Glycémie'),
            'result_type' => 'BOOLEAN',
            'entry_mode' => LabEntryMode::Culture->value,
        ])->assertSessionHasErrors('entry_mode');
        $this->assertFalse(AnalysisCatalog::query()->where('code', 'GLY')->exists());

        $this->actingAs($actor)->post('/administration/analyses', [
            ...$this->payload($prestation, 'GLY', 'NORMAL', 'Glycémie'),
            'result_type' => 'BOOLEAN',
            'entry_mode' => LabEntryMode::AbsencePresence->value,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_every_result_type_offers_at_least_one_mode_and_a_legacy_type_that_no_longer_fits_is_ignored(): void
    {
        foreach (AnalysisCatalog::RESULT_TYPES as $type) {
            $this->assertNotEmpty(LabEntryMode::forResultType($type), $type);
        }

        $actor = $this->userWithRole('ADMINISTRATION');
        $analysis = $this->definition($this->prestation($actor), [
            'code' => 'ECBU', 'designation' => 'Culture',
            'result_type' => 'NUMERIC',
            'source_metadata' => ['type_name' => 'GERME'],
        ]);

        $this->assertSame(LabEntryMode::Numeric, LabEntryMode::for($analysis));
        $this->assertSame('result_type', LabEntryMode::sourceFor($analysis));
    }

    public function test_sheets_and_report_sections_follow_the_order_of_the_referential(): void
    {
        LabDiscipline::query()->create(['name' => 'Biochimie', 'display_order' => 20, 'is_active' => true]);
        LabDiscipline::query()->create(['name' => 'Hématologie', 'display_order' => 10, 'is_active' => true]);

        $labels = [LabDisciplines::NONE, LabDisciplines::label('Biochimie'), LabDisciplines::label('Hématologie')];
        usort($labels, fn ($a, $b) => app(LabDisciplines::class)->sorter()($a) <=> app(LabDisciplines::class)->sorter()($b));

        $this->assertSame([LabDisciplines::label('Hématologie'), LabDisciplines::label('Biochimie'), LabDisciplines::NONE], $labels);
    }

    /** @return array<string, mixed> */
    private function payload($prestation, string $code, string $level, string $designation): array
    {
        return [
            'catalog_item_uuid' => $prestation->uuid,
            'parent_uuid' => null,
            'code' => $code,
            'level' => $level,
            'designation' => $designation,
            'result_type' => 'TEXT',
            'predefined_values' => [],
            'display_order' => 1,
            'is_active' => true,
        ];
    }
}
