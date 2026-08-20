<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\CancelBillableItemAction;
use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Actions\Cash\OpenCashSessionAction;
use App\Actions\Medicine\CreateConsultationAction;
use App\Actions\Payment\RecordPaymentAction;
use App\Enums\BillableItemStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Finance\BillableItemFinancialClearance;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BillableItemFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Patient, 2: Episode}
     */
    private function context(): array
    {
        $actor = User::factory()->create();
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => EpisodeAdministrativeStatus::Oriented,
            'started_at' => now(),
            'created_by' => $actor->id,
        ]);

        return [$actor, $patient, $episode];
    }

    private function item(
        Episode $episode,
        User $actor,
        string $module,
        string $description,
        bool $requiresPriorPayment = false,
    ): BillableItem {
        return $this->app->make(RecordBillableItemAction::class)->execute($episode, [
            'source_module' => $module,
            'description' => $description,
            'quantity' => '1',
            'unit_price' => '1000.50',
            'payment_required_before_fulfillment' => $requiresPriorPayment,
        ], $actor);
    }

    public function test_business_modules_create_items_that_reception_can_invoice_only_once(): void
    {
        [$actor, $patient, $episode] = $this->context();
        $laboratoryItem = $this->item($episode, $actor, 'LABORATORY', 'NFS', true);

        $invoice = $this->app->make(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$laboratoryItem->uuid],
        ], $actor);

        $this->assertSame('1000.50', $invoice->total_amount);
        $this->assertSame(BillableItemStatus::Invoiced, $laboratoryItem->fresh()->status);
        $this->assertSame($laboratoryItem->id, $invoice->lines()->sole()->billable_item_id);

        $this->expectException(ValidationException::class);

        $this->app->make(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$laboratoryItem->uuid],
        ], $actor);
    }

    public function test_manual_reception_lines_are_first_recorded_as_billable_items(): void
    {
        [$actor, $patient, $episode] = $this->context();

        $invoice = $this->app->make(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'lines' => [[
                'description' => 'Frais administratif',
                'quantity' => '2',
                'unit_price' => '125.25',
            ]],
        ], $actor);

        $item = BillableItem::query()->sole();
        $this->assertSame('RECEPTION', $item->source_module);
        $this->assertSame('250.50', $item->total_amount);
        $this->assertSame($item->id, $invoice->lines()->sole()->billable_item_id);
    }

    public function test_laboratory_and_pharmacy_use_the_same_read_only_financial_clearance(): void
    {
        [$actor, $patient, $episode] = $this->context();
        $laboratoryItem = $this->item($episode, $actor, 'LABORATORY', 'NFS', true);
        $pharmacyItem = $this->item($episode, $actor, 'PHARMACY', 'Paracétamol', true);
        $clearance = $this->app->make(BillableItemFinancialClearance::class);

        $this->assertFalse($clearance->isSatisfied($laboratoryItem));
        $this->assertFalse($clearance->isSatisfied($pharmacyItem));

        $invoice = $this->app->make(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$laboratoryItem->uuid, $pharmacyItem->uuid],
        ], $actor);
        $this->app->make(ValidateInvoiceAction::class)->execute($invoice, $actor);

        $this->assertFalse($clearance->isSatisfied($laboratoryItem->fresh()));
        $this->assertFalse($clearance->isSatisfied($pharmacyItem->fresh()));

        (new PaymentMethodSeeder)->run();
        $method = PaymentMethod::query()->where('code', 'CASH')->sole();
        $this->app->make(OpenCashSessionAction::class)->execute('0', null, $actor);
        $this->app->make(RecordPaymentAction::class)->execute($patient, [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $method->id,
            'amount' => '1000.50',
        ], $actor);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertFalse($clearance->isSatisfied($laboratoryItem->fresh()));
        $this->assertFalse($clearance->isSatisfied($pharmacyItem->fresh()));

        $this->app->make(RecordPaymentAction::class)->execute($patient, [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $method->id,
            'amount' => '1000.50',
        ], $actor);

        $this->assertTrue($clearance->isSatisfied($laboratoryItem->fresh()));
        $this->assertTrue($clearance->isSatisfied($pharmacyItem->fresh()));
    }

    public function test_cancelled_item_remains_visible_audited_and_non_deletable(): void
    {
        [$actor, , $episode] = $this->context();
        $item = $this->item($episode, $actor, 'SURGERY', 'Kit opératoire');

        $this->app->make(CancelBillableItemAction::class)
            ->execute($item, 'Acte non réalisé', $actor);

        $this->assertDatabaseHas('billable_items', [
            'id' => $item->id,
            'status' => 'CANCELLED',
            'cancellation_reason' => 'Acte non réalisé',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing.item.cancel',
            'entity_id' => $item->id,
            'reason' => 'Acte non réalisé',
        ]);

        $this->expectException(LogicException::class);
        $item->delete();
    }

    public function test_unpaid_financial_items_do_not_block_medicine_or_change_medical_state(): void
    {
        [$doctor, $patient, $episode] = $this->context();
        $this->actingAs($doctor);
        $this->item($episode, $doctor, 'LABORATORY', 'Analyse en attente', true);

        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, [
            'reason' => 'Douleur aiguë',
        ]);

        $this->assertNotNull($consultation->id);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
        $this->assertNull($episode->fresh()->financial_status);
        $this->assertSame($patient->id, $consultation->episode->patient_id);
    }

    public function test_payment_state_does_not_overwrite_medical_or_administrative_state(): void
    {
        [$actor, $patient, $episode] = $this->context();
        $episode->update(['medical_status' => 'IN_CONSULTATION']);
        $item = $this->item($episode, $actor, 'MEDICINE', 'Consultation');
        $invoice = $this->app->make(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$item->uuid],
        ], $actor);
        $this->app->make(ValidateInvoiceAction::class)->execute($invoice, $actor);

        (new PaymentMethodSeeder)->run();
        $method = PaymentMethod::query()->where('code', 'CASH')->sole();
        $this->app->make(OpenCashSessionAction::class)->execute('0', null, $actor);
        $this->app->make(RecordPaymentAction::class)->execute($patient, [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $method->id,
            'amount' => '1000.50',
        ], $actor);

        $episode->refresh();
        $this->assertSame('IN_CONSULTATION', $episode->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->administrative_status);
        $this->assertNull($episode->financial_status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }
}
