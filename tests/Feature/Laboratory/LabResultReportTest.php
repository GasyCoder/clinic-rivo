<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\AnalysisCatalog;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\LabAnalysisNote;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\User;
use App\Services\Laboratory\LabResultReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-218 — la note de chaque ligne d'analyse et le compte rendu PDF, tel que le
 * laboratoire le remet : sections par discipline, Résultat · Val. réf. ·
 * Antériorité, notes (conclusions partielles), conclusion générale ; ADR-219 :
 * gras selon le catalogue, plus de conclusion par analyse, remise à zéro.
 */
class LabResultReportTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    /** @return array{0: CatalogItem, 1: array<string, AnalysisCatalog>} un hémogramme : un groupe, deux lignes */
    private function hemogram(User $actor): array
    {
        $nfs = $this->prestation($actor, 'LAB-NFS', 'NFS');
        $group = $this->definition($nfs, ['code' => 'HEMO', 'designation' => 'HEMOGRAMME', 'level' => 'PARENT', 'result_type' => 'LABEL', 'exam_category' => 'HEMATOLOGIE', 'is_bold' => true]);
        $hb = $this->definition($nfs, ['parent_id' => $group->id, 'code' => 'HB', 'designation' => 'Hémoglobine', 'result_type' => 'NUMERIC', 'unit' => 'g/dL', 'reference_general' => '12 - 16', 'display_order' => 1, 'exam_category' => 'HEMATOLOGIE']);
        $gb = $this->definition($nfs, ['parent_id' => $group->id, 'code' => 'GB', 'designation' => 'Globules blancs', 'result_type' => 'NUMERIC', 'unit' => '10³/µL', 'reference_general' => '4 - 10', 'display_order' => 2, 'exam_category' => 'HEMATOLOGIE']);

        return [$nfs, ['group' => $group, 'hb' => $hb, 'gb' => $gb]];
    }

    /** @return array{0: LabRequest, 1: LabRequestItem, 2: User, 3: array<string, AnalysisCatalog>} */
    private function workedRequest(User $prescriber): array
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        [$nfs, $rows] = $this->hemogram($technician);
        $request = $this->labRequest($episode, $orientation, $prescriber);
        $request->update(['notes' => 'Crise convulsive']);
        $item = $this->requestItem($request, $nfs);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [
                ['analysis_uuid' => $rows['hb']->uuid, 'value' => '9,8'],
                ['analysis_uuid' => $rows['gb']->uuid, 'value' => '6,2'],
            ],
            'notes' => [
                ['analysis_uuid' => $rows['group']->uuid, 'note' => 'Microcytose isolée avec polynucléose neutrophile.'],
                ['analysis_uuid' => $rows['hb']->uuid, 'note' => 'À contrôler dans 15 jours.'],
            ],
        ])->assertSessionHasNoErrors();

        return [$request, $item->fresh(), $technician, $rows];
    }

    public function test_a_note_is_kept_per_analysis_line_and_an_empty_note_removes_it(): void
    {
        [, $item, $technician, $rows] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $this->assertSame(2, LabAnalysisNote::query()->count());
        $this->assertSame('À contrôler dans 15 jours.', LabAnalysisNote::query()->where('analysis_catalog_id', $rows['hb']->id)->value('note'));
        $this->assertSame($technician->id, LabAnalysisNote::query()->where('analysis_catalog_id', $rows['hb']->id)->value('written_by'));

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [], 'notes' => [['analysis_uuid' => $rows['hb']->uuid, 'note' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, LabAnalysisNote::query()->count());
        $this->assertNull(LabAnalysisNote::query()->where('analysis_catalog_id', $rows['hb']->id)->first());
    }

    /**
     * Un intitulé (« Soit » dans la NFS) sépare deux blocs de lignes : il est servi
     * comme tel et ne porte pas de conclusion ; un vrai groupe en garde une.
     */
    public function test_a_simple_heading_is_a_separator_and_takes_no_conclusion(): void
    {
        [$request, $item, $technician, $rows] = $this->workedRequest($this->userWithRole('MEDICINE'));
        $soit = $this->definition($item->catalogItem, [
            'parent_id' => $rows['group']->id, 'code' => 'SOIT', 'designation' => 'Soit',
            'result_type' => 'TEXT', 'entry_mode' => 'LABEL', 'display_order' => 3,
        ]);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [], 'notes' => [['analysis_uuid' => $soit->uuid, 'note' => 'Valeurs absolues']],
        ])->assertSessionHasErrors('notes.0.note');
        $this->assertNull(LabAnalysisNote::query()->where('analysis_catalog_id', $soit->id)->first());

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('items.0.nodes', fn ($nodes) => collect($nodes)->firstWhere('uuid', $soit->uuid)['is_label'] === true
                    && collect($nodes)->firstWhere('uuid', $rows['group']->uuid)['is_label'] === false));
    }

    public function test_a_note_on_an_analysis_of_another_prestation_is_refused(): void
    {
        [, $item, $technician] = $this->workedRequest($this->userWithRole('MEDICINE'));
        $other = $this->definition($this->prestation($technician, 'LAB-GLY', 'Glycémie'), ['code' => 'GLY', 'designation' => 'Glycémie']);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [], 'notes' => [['analysis_uuid' => $other->uuid, 'note' => 'Hors sujet']],
        ])->assertSessionHasErrors('notes.0.analysis_uuid');
    }

    public function test_the_page_serves_each_line_with_its_note(): void
    {
        [$request, , $technician] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Show', false)
                ->where('items.0.nodes.0.note', 'Microcytose isolée avec polynucléose neutrophile.')
                ->where('items.0.nodes.1.note', 'À contrôler dans 15 jours.'));
    }

    public function test_the_report_reads_like_the_laboratory_sheet(): void
    {
        [$request, $item] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $report = app(LabResultReport::class)->compose($request->fresh(), collect([$item]));
        $section = $report['sections'][0];
        $rows = $section['items'][0]['rows'];

        $this->assertSame('HEMATOLOGIE', $section['title']);
        $this->assertSame('Crise convulsive', $report['request']['clinical_notes']);
        $this->assertFalse($section['items'][0]['title_row'], 'une seule racine : HEMOGRAMME est le titre');
        $this->assertSame(['heading', 'result', 'note', 'result', 'note'], array_column($rows, 'kind'));
        $this->assertSame('9,8 g/dL', $rows[1]['value']);
        $this->assertTrue($rows[1]['pathological']);
        $this->assertSame('Microcytose isolée avec polynucléose neutrophile.', $rows[4]['text'], 'la note du groupe suit sa dernière ligne');
        $this->assertTrue($rows[0]['bold'], 'HEMOGRAMME est en gras au catalogue');
        $this->assertFalse($rows[1]['bold'], 'Hémoglobine ne l’est pas');
        $this->assertArrayNotHasKey('conclusion', $section['items'][0], 'plus de conclusion par analyse');
        $this->assertTrue($report['provisional']);
    }

    public function test_the_laboratory_downloads_the_pdf(): void
    {
        [$request, $item, $technician] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $response = $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/resultats.pdf");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        $download = $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/resultats.pdf?telecharger=1");
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Rakoto', (string) $download->headers->get('Content-Disposition'));

        if ($path = getenv('RIVO_LAB_PDF_DUMP')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_the_physician_pdf_holds_only_what_was_sent(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->workedRequest($doctor);

        // Rien d'envoyé : rien à lire.
        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}/pdf")->assertNotFound();

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->get("/resultats-analyses/{$request->uuid}/pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Adressé au Dr A : un confrère doit d'abord l'ouvrir (ADR-216).
        $colleague = $this->userWithRole('MEDICINE');
        $this->actingAs($colleague)->get("/resultats-analyses/{$request->uuid}/pdf")->assertForbidden();
    }

    public function test_the_previous_value_of_the_same_line_is_printed_as_anteriority(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        [$nfs, $rows] = $this->hemogram($technician);

        $first = $this->labRequest($episode, $orientation, $doctor);
        $old = $this->requestItem($first, $nfs);
        $this->actingAs($technician)->put("/laboratory/items/{$old->uuid}/results", [
            'results' => [['analysis_uuid' => $rows['hb']->uuid, 'value' => '11,4']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($technician)->post("/laboratory/requests/{$first->uuid}/send", [
            'items' => [$old->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();

        $second = $this->labRequest($episode, $orientation, $doctor);
        $item = $this->requestItem($second, $nfs);
        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $rows['hb']->uuid, 'value' => '9,8']],
        ])->assertSessionHasNoErrors();

        $report = app(LabResultReport::class)->compose($second->fresh(), collect([$item->fresh()]));
        $hb = collect($report['sections'][0]['items'][0]['rows'])->firstWhere('kind', 'result');

        $this->assertStringStartsWith('11,4 g/dL (', (string) $hb['anteriority']);
    }

    public function test_a_heading_that_is_not_bold_in_the_catalog_is_not_printed_bold(): void
    {
        [$request, $item, , $rows] = $this->workedRequest($this->userWithRole('MEDICINE'));
        $rows['group']->update(['is_bold' => false]);

        $report = app(LabResultReport::class)->compose($request->fresh(), collect([$item->fresh()]));

        $this->assertFalse($report['sections'][0]['items'][0]['rows'][0]['bold']);
    }

    public function test_the_item_conclusion_is_no_longer_written(): void
    {
        [, $item, $technician] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [], 'conclusion' => 'Anémie modérée.',
        ])->assertSessionHasNoErrors();

        $this->assertNull($item->fresh()->conclusion);
    }

    public function test_a_reset_erases_the_entry_and_is_traced(): void
    {
        [$request, $item, $technician] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/reset")->assertSessionHasNoErrors();

        $this->assertSame(0, LabResult::query()->where('lab_request_item_id', $item->id)->count());
        $this->assertSame(0, LabAnalysisNote::query()->count());
        $this->assertSame(LabItemStatus::Pending, $item->fresh()->currentStatus());
        $audit = AuditLog::query()->where('action', 'laboratory.results.reset')->sole();
        $this->assertSame($technician->id, $audit->user_id);
        $this->assertStringContainsString('Hémoglobine', json_encode($audit->old_values, JSON_UNESCAPED_UNICODE));

        // Rien à effacer : le geste est refusé plutôt que muet.
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/reset")->assertSessionHasErrors('item');

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('items.0.resettable', true));
    }

    public function test_an_analysis_sent_to_the_physician_is_never_reset(): void
    {
        $doctor = $this->userWithRole('MEDICINE');
        [$request, $item, $technician] = $this->workedRequest($doctor);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/send", [
            'items' => [$item->uuid], 'recipient_uuid' => $doctor->uuid,
        ])->assertSessionHasNoErrors();
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/return", ['reason' => 'Valeur incohérente'])->assertSessionHasNoErrors();

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/reset")->assertSessionHasErrors('item');
        $this->assertSame(2, LabResult::query()->where('lab_request_item_id', $item->id)->count());
    }

    public function test_a_reset_needs_the_entry_permission(): void
    {
        [, $item] = $this->workedRequest($this->userWithRole('MEDICINE'));

        $this->actingAs($this->userWithRole('RECEPTION'))->post("/laboratory/items/{$item->uuid}/reset")->assertForbidden();
    }
}
