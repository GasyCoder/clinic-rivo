<?php

namespace Tests\Feature\Billing;

use App\Models\CashMovement;
use App\Models\CashSession;
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
            'administrative_status' => 'IN_CARE',
            'started_at' => now(),
            'created_by' => $creator->id,
        ]);

        return [$patient, $episode];
    }

    private function createInvoice(User $user, Patient $patient, Episode $episode): Invoice
    {
        $this->actingAs($user)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $episode->uuid,
            'lines' => [
                ['description' => 'Consultation', 'quantity' => 2, 'unit_price' => '1500.50'],
                ['description' => 'Pansement', 'quantity' => 1, 'unit_price' => '1000'],
            ],
        ])->assertRedirect();

        return Invoice::query()->sole();
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
                ->where('openCashSession', null));

        $this->actingAs($viewer)->get('/cash')->assertForbidden();
        $this->actingAs($viewer)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $episode->uuid,
            'lines' => [['description' => 'Acte', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertForbidden();
        $this->actingAs($viewer)->post("/patients/{$patient->uuid}/payments", [])->assertForbidden();
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

        $this->actingAs($user)->post("/patients/{$patient->uuid}/invoices", [
            'episode_uuid' => $otherEpisode->uuid,
            'lines' => [['description' => 'Acte', 'quantity' => 1, 'unit_price' => 1000]],
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
                ->where('receipt.payment.invoice.patient.uuid', $patient->uuid));

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
}
