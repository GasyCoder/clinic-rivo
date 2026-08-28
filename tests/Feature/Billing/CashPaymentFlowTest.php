<?php

namespace Tests\Feature\Billing;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CashPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names, string $roleCode = 'RECEPTION'): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($names as $name) {
            $permission = Permission::query()->create(['name' => $name, 'label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /**
     * @return array{0: Patient, 1: Episode}
     */
    private function patientWithEpisode(
        User $creator,
        string $patientNumber = 'M-000001',
        string $episodeNumber = 'ME-000001',
    ): array {
        $patient = Patient::create([
            'patient_number' => $patientNumber,
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $episodeNumber,
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $creator->id,
            'administrative_status' => 'IN_CARE',
            'started_at' => now(),
            'created_by' => $creator->id,
        ]);

        return [$patient, $episode];
    }

    private function createInvoice(User $user, Patient $patient, Episode $episode): Invoice
    {
        $consultation = $this->catalogItem($user, 'Consultation', '1500.50');
        $dressing = $this->catalogItem($user, 'Pansement', '1000.00');

        $this->actingAs($user)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $episode->uuid,
            'catalog_lines' => [
                ['catalog_item_uuid' => $consultation->uuid, 'quantity' => 2],
                ['catalog_item_uuid' => $dressing->uuid, 'quantity' => 1],
            ],
        ])->assertRedirect();

        return Invoice::query()->sole();
    }

    private function catalogItem(User $actor, string $name = 'Acte', string $amount = '1000.00'): CatalogItem
    {
        $item = CatalogItem::create([
            'code' => 'BILL-'.str_pad((string) (CatalogItem::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Reception,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        CatalogTariff::create([
            'catalog_item_id' => $item->id,
            'amount' => $amount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $actor->id,
        ]);

        return $item;
    }

    public function test_financial_data_and_routes_are_hidden_without_their_permissions(): void
    {
        $viewer = $this->userWithPermissions(['patients.view'], 'MEDICINE');
        [$patient, $episode] = $this->patientWithEpisode($viewer);

        $this->actingAs($viewer)->get("/patients/{$patient->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Show')
                ->where('account', null)
                ->has('paymentMethods', 0)
                ->has('billingCatalog', 0)
                ->where('openCashSession', null));

        $this->actingAs($viewer)->get('/cash')->assertForbidden();
        $this->actingAs($viewer)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $episode->uuid,
            'catalog_lines' => [['catalog_item_uuid' => fake()->uuid(), 'quantity' => 1]],
        ])->assertForbidden();
        $this->actingAs($viewer)->post("/patients/{$patient->uuid}/payments", [])->assertForbidden();
    }

    public function test_cash_workspace_exposes_collectible_invoices_and_active_payment_methods(): void
    {
        $user = $this->userWithPermissions([
            'cash.view', 'billing.view', 'billing.create', 'billing.validate', 'billing.print',
            'payments.create',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();

        $invoice = $this->createInvoice($user, $patient, $episode);
        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();

        $this->actingAs($user)->get('/cash')
            ->assertInertia(fn ($page) => $page
                ->component('Cash/Index')
                ->where('cashSession', null)
                ->where('outstandingSummary.count', 1)
                ->where('outstandingSummary.balance_amount', '4001.00')
                ->has('outstandingInvoices', 1)
                ->where('outstandingInvoices.0.uuid', $invoice->uuid)
                ->where('outstandingInvoices.0.status', 'VALIDATED')
                ->where('outstandingInvoices.0.lines_count', 2)
                ->where('outstandingInvoices.0.patient.uuid', $patient->uuid)
                ->has('paymentMethods', 5));

        $this->actingAs($user)->get("/invoices/{$invoice->uuid}?from=cash")
            ->assertInertia(fn ($page) => $page
                ->component('Invoices/Show')
                ->where('returnToCash', true));
    }

    public function test_cash_workspace_does_not_expose_billing_or_payment_method_data_without_permissions(): void
    {
        $user = $this->userWithPermissions(['cash.view']);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();

        Invoice::create([
            'patient_id' => $patient->id,
            'episode_id' => $episode->id,
            'invoice_number' => 'AI-PRIVATE',
            'source_module' => 'PHARMACY',
            'status' => 'VALIDATED',
            'currency' => 'MGA',
            'subtotal_amount' => '1000.00',
            'discount_amount' => '0.00',
            'total_amount' => '1000.00',
            'paid_amount' => '0.00',
            'balance_amount' => '1000.00',
            'created_by' => $user->id,
            'validated_by' => $user->id,
            'validated_at' => now(),
        ]);

        $this->actingAs($user)->get('/cash')
            ->assertInertia(fn ($page) => $page
                ->component('Cash/Index')
                ->where('outstandingSummary', null)
                ->has('outstandingInvoices', 0)
                ->has('paymentMethods', 0)
                ->has('recentPayments', 0));

        $this->actingAs($user)->get('/cash?pharmacy_reference=AI-PRIVATE')
            ->assertInertia(fn ($page) => $page
                ->where('pharmacyLookup', null)
                ->has('outstandingInvoices', 0));
    }

    public function test_invoice_is_computed_validated_and_exposed_on_the_patient_account(): void
    {
        $user = $this->userWithPermissions([
            'patients.view', 'billing.view', 'billing.create', 'billing.validate', 'payments.view',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);

        $invoice = $this->createInvoice($user, $patient, $episode);

        $this->assertSame('DRAFT', $invoice->status->value);
        $this->assertSame('4001.00', $invoice->total_amount);
        $this->assertSame('4001.00', $invoice->balance_amount);
        $this->assertCount(2, $invoice->lines);

        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'VALIDATED',
            'validated_by' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing.validate',
            'module' => 'billing',
            'entity_id' => $invoice->id,
        ]);

        $this->actingAs($user)->get("/patients/{$patient->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('account.total_amount', '4001.00')
                ->where('account.paid_amount', '0.00')
                ->where('account.balance_amount', '4001.00')
                ->has('account.invoices', 1)
                ->where('account.invoices.0.invoice_number', $invoice->invoice_number)
                ->where('account.invoices.0.status', 'VALIDATED'));
    }

    public function test_an_episode_from_another_patient_cannot_be_invoiced(): void
    {
        $user = $this->userWithPermissions(['billing.create']);
        [$patient] = $this->patientWithEpisode($user);
        [, $otherEpisode] = $this->patientWithEpisode($user, 'M-000002', 'ME-000002');
        $catalogItem = $this->catalogItem($user);

        $this->actingAs($user)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $otherEpisode->uuid,
            'catalog_lines' => [['catalog_item_uuid' => $catalogItem->uuid, 'quantity' => 1]],
        ])->assertSessionHasErrors('episode_uuid');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_only_one_cash_session_can_be_open(): void
    {
        $user = $this->userWithPermissions(['cash.open']);

        $this->actingAs($user)->post('/cash/open', [
            'opening_amount' => 10000,
        ])->assertRedirect();

        $this->actingAs($user)->post('/cash/open', [
            'opening_amount' => 20000,
        ])->assertSessionHasErrors('cash_session');

        $this->assertDatabaseCount('cash_sessions', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cash.open',
            'module' => 'cash',
            'user_id' => $user->id,
        ]);
    }

    public function test_successive_payments_create_receipts_and_close_cash_with_physical_variance(): void
    {
        $user = $this->userWithPermissions([
            'billing.create', 'billing.validate', 'payments.create', 'payments.view',
            'cash.open', 'cash.close', 'cash.view', 'receipts.view',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();
        $cashMethod = PaymentMethod::query()->where('code', 'CASH')->sole();
        $mobileMethod = PaymentMethod::query()->where('code', 'MOBILE_MONEY')->sole();

        $invoice = $this->createInvoice($user, $patient, $episode);
        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();

        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $cashMethod->id,
            'amount' => '1000.00',
        ])->assertSessionHasErrors('cash_session');

        $this->actingAs($user)->post('/cash/open', ['opening_amount' => '10000.00'])->assertRedirect();

        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $cashMethod->id,
            'amount' => '5000.00',
        ])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);

        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $cashMethod->id,
            'amount' => '1500.50',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('PARTIALLY_PAID', $invoice->status->value);
        $this->assertSame('1500.50', $invoice->paid_amount);
        $this->assertSame('2500.50', $invoice->balance_amount);

        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $mobileMethod->id,
            'amount' => '2500.50',
            'reference' => 'MVOLA-123',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('PAID', $invoice->status->value);
        $this->assertSame('4001.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->balance_amount);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('receipts', 2);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertSame(1, CashMovement::query()->where('affects_cash_balance', true)->count());
        $this->assertSame(2, Payment::query()->where('received_by', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.create',
            'module' => 'cash',
            'user_id' => $user->id,
        ]);

        $receipt = Receipt::query()->first();
        $this->actingAs($user)->get("/receipts/{$receipt->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Receipts/Show')
                ->where('receipt.uuid', $receipt->uuid)
                ->where('returnToCash', false)
                ->where('receipt.payment.invoice.patient.uuid', $patient->uuid));

        $this->actingAs($user)->get("/receipts/{$receipt->uuid}?from=cash")
            ->assertInertia(fn ($page) => $page
                ->component('Receipts/Show')
                ->where('returnToCash', true));

        // Mobile money is collected but is not physically present in the
        // drawer. Expected cash = 10,000 opening + 1,500.50 cash payment.
        $this->actingAs($user)->post('/cash/close', [
            'actual_closing_amount' => '11500.50',
        ])->assertRedirect();

        $session = CashSession::query()->sole();
        $this->assertSame('CLOSED', $session->status->value);
        $this->assertSame('11500.50', $session->expected_closing_amount);
        $this->assertSame('11500.50', $session->actual_closing_amount);
        $this->assertSame('0.00', $session->variance_amount);
        $this->assertNull($session->active_key);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cash.close',
            'module' => 'cash',
            'user_id' => $user->id,
        ]);
    }

    public function test_financial_records_cannot_be_deleted(): void
    {
        $user = $this->userWithPermissions(['billing.create']);
        [$patient, $episode] = $this->patientWithEpisode($user);
        $invoice = $this->createInvoice($user, $patient, $episode);

        $this->expectException(LogicException::class);
        $invoice->delete();
    }

    public function test_financial_records_cannot_be_bulk_deleted(): void
    {
        $this->expectException(LogicException::class);
        Invoice::query()->delete();
    }

    public function test_payment_cancellation_reverses_the_open_cash_and_preserves_history(): void
    {
        config()->set('rivo.site.code', 'MAMPIKONY');
        config()->set('rivo.site.name', 'Clinique Saint Georges — Mampikony');
        $user = $this->userWithPermissions([
            'patients.view', 'billing.view', 'billing.create', 'billing.validate',
            'payments.view', 'payments.create', 'payments.cancel', 'cash.open',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();
        $cashMethod = PaymentMethod::query()->where('code', 'CASH')->sole();
        $invoice = $this->createInvoice($user, $patient, $episode);

        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();
        $this->actingAs($user)->post('/cash/open', ['opening_amount' => '10000.00'])->assertRedirect();
        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $cashMethod->id,
            'amount' => '1500.50',
        ])->assertRedirect();

        $payment = Payment::query()->sole();
        $receipt = $payment->receipt()->sole();

        $this->actingAs($user)->post("/payments/{$payment->uuid}/cancel", [
            'reason' => 'Montant saisi par erreur',
        ])->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'CANCELLED',
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'Montant saisi par erreur',
        ]);
        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'payment_id' => $payment->id]);
        $this->assertDatabaseHas('cash_movements', [
            'reversal_payment_id' => $payment->id,
            'type' => 'PAYMENT_CANCELLATION',
            'direction' => 'OUT',
            'amount' => '1500.50',
        ]);
        $this->assertSame('VALIDATED', $invoice->fresh()->status->value);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
        $this->assertSame('4001.00', $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.cancel',
            'module' => 'cash',
            'entity_id' => $payment->id,
            'site_code' => 'MAMPIKONY',
            'reason' => 'Montant saisi par erreur',
        ]);

        $this->actingAs($user)->get("/patients/{$patient->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('account.invoices.0.payments.0.status', 'CANCELLED')
                ->where('account.invoices.0.payments.0.receipt.uuid', $receipt->uuid));

        $this->actingAs($user)->post("/payments/{$payment->uuid}/cancel", [
            'reason' => 'Deuxième tentative',
        ])->assertSessionHasErrors('payment');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_payment_from_a_closed_cash_cannot_be_cancelled_by_the_simple_flow(): void
    {
        $user = $this->userWithPermissions([
            'billing.create', 'billing.validate', 'payments.create', 'payments.cancel',
            'cash.open', 'cash.close',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();
        $method = PaymentMethod::query()->where('code', 'CASH')->sole();
        $invoice = $this->createInvoice($user, $patient, $episode);

        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();
        $this->actingAs($user)->post('/cash/open', ['opening_amount' => '0'])->assertRedirect();
        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $method->id,
            'amount' => '1000',
        ])->assertRedirect();
        $this->actingAs($user)->post('/cash/close', ['actual_closing_amount' => '1000'])->assertRedirect();

        $payment = Payment::query()->sole();
        $this->actingAs($user)->post("/payments/{$payment->uuid}/cancel", [
            'reason' => 'Erreur tardive',
        ])->assertSessionHasErrors('payment');

        $this->assertSame('COMPLETED', $payment->fresh()->status->value);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_completed_payment_cannot_be_deleted(): void
    {
        $user = $this->userWithPermissions([
            'billing.create', 'billing.validate', 'payments.create', 'cash.open',
        ]);
        [$patient, $episode] = $this->patientWithEpisode($user);
        (new PaymentMethodSeeder)->run();
        $method = PaymentMethod::query()->where('code', 'CASH')->sole();
        $invoice = $this->createInvoice($user, $patient, $episode);

        $this->actingAs($user)->post("/invoices/{$invoice->uuid}/validate")->assertRedirect();
        $this->actingAs($user)->post('/cash/open', ['opening_amount' => '0'])->assertRedirect();
        $this->actingAs($user)->post("/patients/{$patient->uuid}/payments", [
            'invoice_uuid' => $invoice->uuid,
            'payment_method_id' => $method->id,
            'amount' => '1000',
        ])->assertRedirect();

        $payment = Payment::query()->sole();

        try {
            $payment->forceDelete();
            $this->fail('A completed payment must not be force-deletable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }

        $this->expectException(LogicException::class);
        Payment::query()->forceDelete();
    }
}
