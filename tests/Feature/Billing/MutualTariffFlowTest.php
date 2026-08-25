<?php

namespace Tests\Feature\Billing;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Reception\CompleteEpisodeServicesAction;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\InvoiceStatus;
use App\Enums\PatientType;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\Invoice;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MutualTariffFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.code' => 'M']);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    public function test_standard_and_mutual_tariffs_can_be_active_for_the_same_service(): void
    {
        $service = $this->service();
        $standard = $this->tariff($service, CatalogTariffCategory::Standard, '25000.00');
        $mutual = $this->tariff($service, CatalogTariffCategory::Mutual, '18000.00');

        $service->refresh();

        $this->assertSame($standard->id, $service->currentStandardTariff()->sole()->id);
        $this->assertSame($mutual->id, $service->currentMutualTariff()->sole()->id);
        $this->assertDatabaseHas('catalog_tariffs', [
            'catalog_item_id' => $service->id,
            'tariff_category' => CatalogTariffCategory::Standard->value,
            'active_key' => 'CURRENT',
            'amount' => '25000.00',
        ]);
        $this->assertDatabaseHas('catalog_tariffs', [
            'catalog_item_id' => $service->id,
            'tariff_category' => CatalogTariffCategory::Mutual->value,
            'active_key' => 'CURRENT',
            'amount' => '18000.00',
        ]);
    }

    public function test_a_standard_patient_is_billed_with_the_standard_tariff_snapshot(): void
    {
        $service = $this->service();
        $standard = $this->tariff($service, CatalogTariffCategory::Standard, '25000.00');
        $this->tariff($service, CatalogTariffCategory::Mutual, '18000.00');
        $episode = $this->episode($this->patient(PatientType::Standard));

        $result = $this->complete($episode, $service, quantity: 2);

        $request = EpisodeServiceRequest::query()->sole();
        $billable = BillableItem::query()->sole();
        $invoice = Invoice::query()->with('lines')->sole();

        $this->assertNotNull($result->invoice);
        $this->assertNull($result->billingWarning);
        $this->assertSame(CatalogTariffCategory::Standard, $request->tariff_category);
        $this->assertSame($standard->id, $request->catalog_tariff_id);
        $this->assertSame('25000.00', $request->unit_price);
        $this->assertSame(CatalogTariffCategory::Standard, $billable->tariff_category);
        $this->assertSame($standard->id, $billable->catalog_tariff_id);
        $this->assertSame('25000.00', $billable->unit_price);
        $this->assertSame('50000.00', $invoice->total_amount);
        $this->assertSame('25000.00', $invoice->lines->sole()->unit_price);
    }

    public function test_a_mutual_patient_with_active_coverage_is_billed_with_the_mutual_tariff_snapshot(): void
    {
        $service = $this->service();
        $this->tariff($service, CatalogTariffCategory::Standard, '25000.00');
        $mutual = $this->tariff($service, CatalogTariffCategory::Mutual, '18000.00');
        $patient = $this->patient(PatientType::Mutual);
        $this->activeCoverage($patient);
        $episode = $this->episode($patient);

        $result = $this->complete($episode, $service, quantity: 2);

        $request = EpisodeServiceRequest::query()->sole();
        $billable = BillableItem::query()->sole();
        $invoice = Invoice::query()->with('lines')->sole();

        $this->assertNotNull($result->invoice);
        $this->assertNull($result->billingWarning);
        $this->assertSame(CatalogTariffCategory::Mutual, $request->tariff_category);
        $this->assertSame($mutual->id, $request->catalog_tariff_id);
        $this->assertSame('18000.00', $request->unit_price);
        $this->assertSame(CatalogTariffCategory::Mutual, $billable->tariff_category);
        $this->assertSame($mutual->id, $billable->catalog_tariff_id);
        $this->assertSame('18000.00', $billable->unit_price);
        $this->assertSame('36000.00', $invoice->subtotal_amount);
        $this->assertSame('36000.00', $invoice->coverage_amount);
        $this->assertSame('0.00', $invoice->total_amount);
        $this->assertSame('0.00', $invoice->balance_amount);
        $this->assertSame(InvoiceStatus::Covered, $invoice->status);
        $this->assertSame('100.00', $invoice->coverage_rate);
        $this->assertSame('Mutuelle de test', $invoice->mutual_organization_name);
        $this->assertSame('18000.00', $invoice->lines->sole()->unit_price);
        $this->assertSame('36000.00', $invoice->lines->sole()->gross_line_total);
        $this->assertSame('36000.00', $invoice->lines->sole()->coverage_amount);
        $this->assertSame('0.00', $invoice->lines->sole()->line_total);
    }

    public function test_an_eighty_percent_mutual_contract_leaves_only_twenty_percent_to_the_patient(): void
    {
        $service = $this->service();
        $this->tariff($service, CatalogTariffCategory::Standard, '25000.00');
        $this->tariff($service, CatalogTariffCategory::Mutual, '18000.00');
        $patient = $this->patient(PatientType::Mutual);
        $coverage = $this->activeCoverage($patient, '80.00');
        $episode = $this->episode($patient);

        $this->complete($episode, $service, quantity: 2);

        $request = EpisodeServiceRequest::query()->sole();
        $billable = BillableItem::query()->sole();
        $invoice = Invoice::query()->with('lines')->sole();

        $this->assertSame('80.00', $request->coverage_rate);
        $this->assertSame('36000.00', $request->gross_amount);
        $this->assertSame('28800.00', $request->coverage_amount);
        $this->assertSame('7200.00', $request->patient_amount);
        $this->assertSame('28800.00', $billable->coverage_amount);
        $this->assertSame('7200.00', $billable->patient_amount);
        $this->assertSame('36000.00', $invoice->subtotal_amount);
        $this->assertSame('28800.00', $invoice->coverage_amount);
        $this->assertSame('7200.00', $invoice->total_amount);
        $this->assertSame('7200.00', $invoice->balance_amount);
        $this->assertSame(InvoiceStatus::Validated, $invoice->status);
        $this->assertSame('7200.00', $invoice->lines->sole()->line_total);

        // Une convention modifiée demain ne doit jamais recalculer le
        // passage ou la facture déjà validés aujourd'hui.
        $coverage->organization()->update(['coverage_rate' => '100.00']);

        $this->assertSame('80.00', $request->fresh()->coverage_rate);
        $this->assertSame('28800.00', $billable->fresh()->coverage_amount);
        $this->assertSame('28800.00', $invoice->fresh()->coverage_amount);
        $this->assertSame('7200.00', $invoice->balance_amount);
    }

    public function test_a_missing_mutual_tariff_never_falls_back_and_keeps_the_clinical_plan(): void
    {
        $service = $this->service();
        $standard = $this->tariff($service, CatalogTariffCategory::Standard, '25000.00');
        $patient = $this->patient(PatientType::Mutual);
        $this->activeCoverage($patient);
        $episode = $this->episode($patient);

        $result = $this->complete($episode, $service);

        $episode->refresh();
        $request = EpisodeServiceRequest::query()->sole();

        $this->assertNotNull($episode->service_plan_finalized_at);
        $this->assertSame(CatalogTariffCategory::Mutual, $request->tariff_category);
        $this->assertNull($request->catalog_tariff_id);
        $this->assertNull($request->unit_price);
        $this->assertNotSame($standard->id, $request->catalog_tariff_id);
        $this->assertNull($result->invoice);
        $this->assertNotNull($result->billingWarning);
        $this->assertStringContainsString('tarif Mutuelle', $result->billingWarning);
        $this->assertDatabaseCount('episode_service_requests', 1);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseCount('billable_items', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    private function service(): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => 'ECHO-TEST',
            'name' => 'Échographie de test',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function tariff(
        CatalogItem $service,
        CatalogTariffCategory $category,
        string $amount,
    ): CatalogTariff {
        return CatalogTariff::query()->create([
            'catalog_item_id' => $service->id,
            'tariff_category' => $category,
            'amount' => $amount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => "Tarif {$category->value} de test",
            'created_by' => $this->actor->id,
        ]);
    }

    private function patient(PatientType $type): Patient
    {
        return Patient::query()->create([
            'patient_number' => 'M-'.fake()->unique()->numerify('26-####'),
            'patient_type' => $type,
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function activeCoverage(Patient $patient, string $coverageRate = '100.00'): PatientMutualCoverage
    {
        $organization = MutualOrganization::query()->create([
            'name' => 'Mutuelle de test',
            'coverage_rate' => $coverageRate,
            'active' => true,
        ]);

        return PatientMutualCoverage::query()->create([
            'patient_id' => $patient->id,
            'mutual_organization_id' => $organization->id,
            'employer_name' => 'Employeur de test',
            'beneficiary_type' => 'PRINCIPAL',
            'membership_number' => 'MUT-TEST-001',
            'created_by' => $this->actor->id,
            'effective_from' => now(),
        ]);
    }

    private function episode(Patient $patient): Episode
    {
        return $this->app->make(CreateEpisodeAction::class)->execute($patient);
    }

    private function complete(
        Episode $episode,
        CatalogItem $service,
        int $quantity = 1,
    ) {
        return $this->app->make(CompleteEpisodeServicesAction::class)->execute(
            episode: $episode,
            actor: $this->actor,
            catalogLines: [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => $quantity,
            ]],
            designationDeferred: false,
            paymentChoice: ArrivalPaymentChoice::Later,
        );
    }
}
