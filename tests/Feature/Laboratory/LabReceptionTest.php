<?php

namespace Tests\Feature\Laboratory;

use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\AnalysisCatalog;
use App\Models\AuditLog;
use App\Models\BillableItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\LabSample;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-214 — la réception au laboratoire : contrôle du règlement, numéro de
 * laboratoire, prélèvements et leurs codes-barres, non-conformité, envoi à un
 * laboratoire extérieur, bornes critiques, conclusion générale.
 */
class LabReceptionTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
        config(['rivo.site.code' => 'A']);
    }

    /** @return array{0: LabSampleType, 1: LabTubeType} */
    private function sampleType(User $actor): array
    {
        $tube = LabTubeType::query()->create(['code' => 'EDTA', 'name' => 'Tube EDTA', 'cap_color' => 'Violet', 'color_hex' => '#7c3aed', 'is_active' => true, 'created_by' => $actor->id]);
        $type = LabSampleType::query()->create(['name' => 'Sang veineux', 'tube_type_id' => $tube->id, 'is_active' => true, 'created_by' => $actor->id]);

        return [$type, $tube];
    }

    private function billable(LabRequestItem $item, User $actor, string $amount = '15000.00', BillableItemStatus $status = BillableItemStatus::Pending): BillableItem
    {
        $billable = BillableItem::create([
            'episode_id' => $item->labRequest->episode_id,
            'source_module' => 'LABORATORY',
            'description' => $item->catalog_item_name_snapshot,
            'quantity' => '1.00',
            'unit_price' => $amount,
            'total_amount' => $amount,
            'gross_amount' => $amount,
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'patient_amount' => $amount,
            'currency' => 'MGA',
            'status' => $status,
            'created_by' => $actor->id,
        ]);
        $item->update(['billable_item_id' => $billable->id]);

        return $billable;
    }

    private function invoiceFor(BillableItem $billable, User $actor, bool $paid): Invoice
    {
        $total = (string) $billable->patient_amount;
        $invoice = Invoice::create([
            'patient_id' => $billable->episode->patient_id,
            'episode_id' => $billable->episode_id,
            'invoice_number' => fake()->unique()->bothify('AF-######'),
            'status' => $paid ? InvoiceStatus::Paid : InvoiceStatus::Validated,
            'currency' => 'MGA',
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid ? $total : '0.00',
            'balance_amount' => $paid ? '0.00' : $total,
            'created_by' => $actor->id,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'billable_item_id' => $billable->id,
            'description' => $billable->description, 'quantity' => '1.00',
            'unit_price' => $total, 'line_total' => $total, 'created_by' => $actor->id,
        ]);
        $billable->update(['status' => BillableItemStatus::Invoiced]);

        return $invoice;
    }

    public function test_reception_numbers_the_request_records_its_samples_and_opens_the_bench(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);
        [$type, $tube] = $this->sampleType($technician);
        $request = $this->labRequest($episode, $orientation, $technician, received: false);
        $this->requestItem($request, $nfs);

        $this->actingAs($technician)->get('/laboratory')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'to_receive')
                ->where('counts.to_receive', 1)
                ->where('requests.data.0.state', 'to_receive')
                ->where('requests.data.0.payment.cleared', true));

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", [
            'samples' => [['sample_type_uuid' => $type->uuid, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('A-L'.now()->format('y').'-00001', $request->lab_number);
        $this->assertNotNull($request->received_at);
        $this->assertSame($technician->id, $request->received_by);

        $samples = LabSample::query()->orderBy('sequence')->get();
        $this->assertCount(2, $samples);
        $this->assertSame(["{$request->lab_number}-1", "{$request->lab_number}-2"], $samples->pluck('barcode')->all());
        // Le tube proposé par le type est figé sur le prélèvement.
        $this->assertSame('EDTA', $samples[0]->tube_code_snapshot);
        $this->assertSame('#7c3aed', $samples[0]->tube_color_hex_snapshot);
        $this->assertTrue(AuditLog::query()->where('action', 'laboratory.request.receive')->exists());

        // Deux fois : refusé, avec qui l'a reçue.
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])
            ->assertSessionHasErrors('request');

        $this->actingAs($technician)->get('/laboratory')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'to_do')->where('counts.to_receive', 0));

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/etiquettes")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Labels', false)
                ->count('samples', 2)
                ->where('samples.0.tube.code', $tube->code));
    }

    public function test_an_unpaid_analysis_holds_the_sampling_until_the_cash_desk_settles_it(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $technician, received: false);
        $item = $this->requestItem($request, $this->prestation($technician));
        $billable = $this->billable($item, $technician);

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])
            ->assertSessionHasErrors(['request' => 'À régler à la Caisse avant le prélèvement : NFS.']);
        $this->assertNull($request->fresh()->received_at);

        // Facturée mais pas encore payée : toujours à régler.
        $invoice = $this->invoiceFor($billable, $technician, paid: false);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])
            ->assertSessionHasErrors('request');

        // Réglée à la Caisse : la demande se reçoit.
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_amount' => $invoice->total_amount, 'balance_amount' => '0.00']);
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($request->fresh()->received_at);
        $this->assertNull($request->fresh()->payment_exemption);
    }

    public function test_an_emergency_and_a_fully_covered_analysis_never_wait_for_payment(): void
    {
        $technician = $this->userWithRole('LABORATORY');

        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $episode->update(['priority' => 'EMERGENCY']);
        $urgent = $this->labRequest($episode, $orientation, $technician, received: false);
        $this->billable($this->requestItem($urgent, $this->prestation($technician)), $technician);

        $this->actingAs($technician)->post("/laboratory/requests/{$urgent->uuid}/receive", ['samples' => []])->assertSessionHasNoErrors();
        $this->assertSame('EMERGENCY', $urgent->fresh()->payment_exemption);

        [$covered, $coveredOrientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($covered, $coveredOrientation, $technician, received: false);
        $this->billable($this->requestItem($request, $this->prestation($technician, 'LAB-CRP', 'CRP')), $technician, '0.00');

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/receive", ['samples' => []])->assertSessionHasNoErrors();
    }

    public function test_no_result_is_entered_before_the_request_is_received(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC']);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician, received: false), $glycemie);

        $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => '1']],
        ])->assertSessionHasErrors('item');

        $this->assertSame(0, LabResult::query()->count());
    }

    public function test_a_rejected_sample_keeps_its_trace_but_loses_its_label(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        [$type] = $this->sampleType($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $this->requestItem($request, $this->prestation($technician));

        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/samples", [
            'samples' => [['sample_type_uuid' => $type->uuid, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $sample = LabSample::query()->orderBy('sequence')->first();

        $this->actingAs($technician)->post("/laboratory/samples/{$sample->uuid}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($technician)->post("/laboratory/samples/{$sample->uuid}/reject", ['reason' => 'Tube hémolysé'])
            ->assertSessionHasNoErrors();

        $sample->refresh();
        $this->assertNotNull($sample->rejected_at);
        $this->assertSame('Tube hémolysé', $sample->rejection_reason);
        $this->assertSame(2, LabSample::query()->count());

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/etiquettes")
            ->assertInertia(fn (AssertableInertia $page) => $page->count('samples', 1));
    }

    public function test_a_scanned_tube_opens_its_request(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        [$type] = $this->sampleType($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $this->requestItem($request, $this->prestation($technician));
        $this->actingAs($technician)->post("/laboratory/requests/{$request->uuid}/samples", ['samples' => [['sample_type_uuid' => $type->uuid]]]);
        $barcode = LabSample::query()->sole()->barcode;

        $this->actingAs($technician)->get('/laboratory?q='.urlencode($barcode))
            ->assertRedirect("/laboratory/requests/{$request->uuid}");
        $this->actingAs($technician)->get('/laboratory?q='.urlencode($request->lab_number))
            ->assertRedirect("/laboratory/requests/{$request->uuid}");
    }

    public function test_an_analysis_sent_out_is_tracked_then_brought_back(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $item = $this->requestItem($request, $this->prestation($technician, 'LAB-HIV', 'Charge virale VIH'));

        $this->actingAs($this->userWithRole('MEDICINE'))->post("/laboratory/items/{$item->uuid}/send-out", ['laboratory' => 'Institut Pasteur'])
            ->assertForbidden();
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/send-out", ['laboratory' => ''])
            ->assertSessionHasErrors('laboratory');
        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/send-out", [
            'laboratory' => 'Institut Pasteur', 'reference' => 'IPM-4521',
        ])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('Institut Pasteur', $item->external_lab_name);
        $this->assertNotNull($item->sent_out_at);

        $this->actingAs($technician)->get('/laboratory?externe=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sentOut', true)
                ->where('counts.sent_out', 1)
                ->where('requests.data.0.items.0.external', 'Institut Pasteur'));
        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/bon-envoi")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/SendOutSlip', false)
                ->where('groups.0.laboratory', 'Institut Pasteur')
                ->where('groups.0.items.0.reference', 'IPM-4521'));
        // Hors de la feuille de paillasse : elle se suit sur son bon.
        $this->actingAs($technician)->get('/laboratory/paillasse')
            ->assertInertia(fn (AssertableInertia $page) => $page->count('sheets', 0));

        $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/send-out/cancel")->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->sent_out_at);
        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/bon-envoi")->assertNotFound();
    }

    public function test_a_value_beyond_a_critical_bound_is_flagged_and_a_dismissal_holds(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $kaliemie = $this->prestation($technician, 'LAB-K', 'Kaliémie');
        $definition = $this->definition($kaliemie, [
            'code' => 'K', 'designation' => 'Potassium', 'result_type' => 'NUMERIC', 'unit' => 'mmol/L',
            'reference_general' => '3,5 - 5', 'critical_ranges' => ['general' => ['low' => 2.5, 'high' => 6.5]],
        ]);
        $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $kaliemie);
        $save = fn (string $value) => $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", [
            'results' => [['analysis_uuid' => $definition->uuid, 'value' => $value]],
        ])->assertSessionHasNoErrors();

        $save('4,2');
        $this->assertFalse(LabResult::query()->sole()->is_critical);

        $save('7,1');
        $result = LabResult::query()->sole();
        $this->assertTrue($result->is_critical);
        $this->assertSame(LabResult::CRITICAL_AUTO, $result->critical_source);
        $this->assertSame('< 2,5 ou > 6,5', $result->critical_snapshot);

        // Le laboratoire retire le signal : il ne revient pas tant que la valeur ne change pas.
        $this->actingAs($technician)->post("/laboratory/results/{$result->uuid}/critical", ['critical' => false])->assertSessionHasNoErrors();
        $save('7,1');
        $this->assertFalse(LabResult::query()->sole()->is_critical);
        $this->assertSame(LabResult::CRITICAL_DISMISSED, LabResult::query()->sole()->critical_source);

        $save('7,4');
        $this->assertTrue(LabResult::query()->sole()->is_critical);

        // Revenue entre les bornes : le signal automatique tombe.
        $save('4,0');
        $this->assertFalse(LabResult::query()->sole()->is_critical);

        $this->actingAs($technician)->get("/laboratory/requests/{$item->labRequest->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('items.0.nodes.0.critical.text', '< 2,5 ou > 6,5'));
    }

    public function test_the_general_conclusion_belongs_to_the_biologist(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $this->requestItem($request, $this->prestation($technician));

        $this->actingAs($this->userWithRole('MEDICINE'))->put("/laboratory/requests/{$request->uuid}/conclusion", ['conclusion' => 'x'])->assertForbidden();
        $this->actingAs($technician)->put("/laboratory/requests/{$request->uuid}/conclusion", ['conclusion' => 'Bilan sans particularité.'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('Bilan sans particularité.', $request->conclusion);
        $this->assertSame($technician->id, $request->conclusion_by);

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}/impression")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('labRequest.conclusion', 'Bilan sans particularité.'));
    }

    public function test_the_worklist_groups_what_is_left_to_do_by_discipline(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $technician);
        $this->requestItem($request, $this->prestation($technician));
        // Pas encore reçue : pas sur la feuille.
        $this->requestItem($this->labRequest($episode, $orientation, $technician, received: false), $this->prestation($technician, 'LAB-CRP', 'CRP'));

        $this->actingAs($technician)->get('/laboratory/paillasse')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Worklist', false)
                ->count('sheets', 1)
                ->count('sheets.0.rows', 1)
                ->where('sheets.0.rows.0.lab_number', $request->lab_number)
                ->where('sheets.0.rows.0.items.0.name', 'NFS'));
    }

    public function test_the_patient_history_lines_up_results_request_by_request(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $glycemie = $this->prestation($technician, 'LAB-GLY', 'Glycémie');
        $definition = $this->definition($glycemie, ['code' => 'GLY', 'designation' => 'Glycémie', 'result_type' => 'NUMERIC', 'unit' => 'g/L']);

        foreach (['0.95', '1.40'] as $value) {
            $item = $this->requestItem($this->labRequest($episode, $orientation, $technician), $glycemie);
            $this->actingAs($technician)->put("/laboratory/items/{$item->uuid}/results", ['results' => [['analysis_uuid' => $definition->uuid, 'value' => $value]]]);
            $this->actingAs($technician)->post("/laboratory/items/{$item->uuid}/complete")->assertSessionHasNoErrors();
        }

        $this->actingAs($technician)->get("/laboratory/patients/{$episode->patient->uuid}/historique")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/PatientHistory', false)
                ->count('columns', 2)
                ->where('total_requests', 2)
                ->where('groups.0.rows.0.designation', 'Glycémie')
                ->where('groups.0.rows.0.unit', 'g/L'));
    }

    public function test_reports_count_the_period_and_export_is_its_own_right(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $this->requestItem($this->labRequest($episode, $orientation, $technician), $this->prestation($technician));
        $this->requestItem($this->labRequest($episode, $orientation, $technician, received: false), $this->prestation($technician, 'LAB-CRP', 'CRP'));

        $this->actingAs($technician)->get('/laboratory/rapports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Reports', false)
                ->where('report.totals.requests', 2)
                ->where('report.totals.requested_items', 2)
                ->where('report.totals.backlog_to_receive', 1)
                ->where('report.delays.request_to_result.median_hours', null)
                ->where('canExport', true));

        $this->actingAs($technician)->get('/laboratory/rapports/export')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'laboratory.reports.export')->exists());

        $this->actingAs($this->userWithRole('RECEPTION'))->get('/laboratory/rapports')->assertForbidden();
    }

    public function test_the_sample_referential_is_managed_without_ever_deleting(): void
    {
        $technician = $this->userWithRole('LABORATORY');

        $this->actingAs($technician)->get('/laboratory/prelevements')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Laboratory/SampleTypes', false)->where('isEmpty', true));

        $this->actingAs($technician)->post('/laboratory/prelevements/referentiel-de-depart')->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, LabTubeType::query()->count());
        $this->assertGreaterThan(0, LabSampleType::query()->count());
        // Rejouer ne double rien.
        $counts = [LabTubeType::query()->count(), LabSampleType::query()->count()];
        $this->actingAs($technician)->post('/laboratory/prelevements/referentiel-de-depart');
        $this->assertSame($counts, [LabTubeType::query()->count(), LabSampleType::query()->count()]);

        $this->actingAs($technician)->post('/laboratory/prelevements/tube', ['code' => 'GRIS', 'name' => 'Tube fluoré', 'cap_color' => 'Gris', 'color_hex' => '#9CA3AF'])
            ->assertSessionHasNoErrors();
        $tube = LabTubeType::query()->where('code', 'GRIS')->sole();
        $this->assertSame('#9ca3af', $tube->color_hex);

        $this->actingAs($technician)->post('/laboratory/prelevements/tube', ['code' => 'gris', 'name' => 'Doublon'])
            ->assertSessionHasErrors('code');

        $this->actingAs($technician)->post('/laboratory/prelevements/sample', ['name' => 'Sang pour glycémie', 'tube_type_uuid' => $tube->uuid])
            ->assertSessionHasNoErrors();

        // Un tube proposé par un type ne s'archive pas.
        $this->actingAs($technician)->delete("/laboratory/prelevements/tube/{$tube->uuid}", ['reason' => 'Plus utilisé'])
            ->assertSessionHasErrors('reason');

        $type = LabSampleType::query()->where('name', 'Sang pour glycémie')->sole();
        $this->actingAs($technician)->delete("/laboratory/prelevements/sample/{$type->uuid}", ['reason' => 'Doublon'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($type);
        $this->actingAs($technician)->post('/laboratory/prelevements/sample', ['name' => 'sang pour glycemie'])
            ->assertSessionHasErrors('name');
        $this->actingAs($technician)->post("/laboratory/prelevements/sample/{$type->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($type);

        $this->actingAs($this->userWithRole('RECEPTION'))->get('/laboratory/prelevements')->assertForbidden();
    }

    public function test_the_catalog_stores_critical_bounds_and_refuses_inverted_ones(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');
        $service = $this->prestation($actor, 'LAB-NA', 'Natrémie');
        $payload = fn (array $ranges) => [
            'catalog_item_uuid' => $service->uuid, 'parent_uuid' => null, 'code' => 'NA', 'level' => 'NORMAL',
            'designation' => 'Sodium', 'result_type' => 'NUMERIC', 'unit' => 'mmol/L', 'predefined_values' => [],
            'display_order' => 1, 'is_active' => true, 'critical_ranges' => $ranges,
        ];

        $this->actingAs($actor)->post('/administration/analyses', $payload(['general' => ['low' => '130', 'high' => '120']]))
            ->assertSessionHasErrors();

        $this->actingAs($actor)->post('/administration/analyses', $payload(['general' => ['low' => '120', 'high' => '155,5'], 'child_male' => ['low' => null, 'high' => null]]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(['general' => ['low' => 120, 'high' => 155.5]], AnalysisCatalog::query()->where('code', 'NA')->sole()->critical_ranges);
    }

    public function test_the_request_page_serves_reception_before_the_bench(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $technician, received: false);
        $this->requestItem($request, $this->prestation($technician));

        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Show', false)
                ->where('labRequest.received', false)
                ->where('payment.cleared', true)
                ->where('payment.lines.0.state', 'NOT_BILLED')
                ->where('can.receive', true));

        LabRequest::query()->whereKey($request->id)->update(['received_at' => now(), 'lab_number' => 'A-L26-00042']);
        $this->actingAs($technician)->get("/laboratory/requests/{$request->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('labRequest.received', true)->where('payment', null));
    }
}
