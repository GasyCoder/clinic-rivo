<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ApplyInvoiceDiscountAction;
use App\Actions\Billing\RemoveInvoiceDiscountAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Enums\DiscountSource;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Api\V1\SuperAdmin\PatientVipSettingsController as SitePatientVipSettingsController;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\DiscountCoupon;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\InvoiceDiscount;
use App\Models\Patient;
use App\Models\PatientDiscount;
use App\Models\PatientStaffLink;
use App\Models\PatientVipSetting;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\InvoiceDiscountResolver;
use App\Services\Billing\InvoiceDiscountTotals;
use App\Services\Settings\AppSettings;
use App\Support\Settings\AppSettingsRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-192 — une seule remise par facture, la plus avantageuse, sur la part patient,
 * posée et retirée par la Caisse tant que rien n'est encaissé.
 */
class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $role = 'RECEPTION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $role], ['name' => $role]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function cashier(): User
    {
        return $this->userWith(['discounts.view', 'discounts.create', 'billing.view']);
    }

    private function patient(): Patient
    {
        return Patient::create([
            'patient_number' => fake()->unique()->bothify('A-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-04-14',
            'sex' => 'F',
        ]);
    }

    /** Une facture de 50 000 Ar dont la mutuelle couvre `$coverage`. */
    private function invoice(User $actor, ?Patient $patient = null, string $coverage = '0.00', InvoiceStatus $status = InvoiceStatus::Validated): Invoice
    {
        $patient ??= $this->patient();
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-'.fake()->unique()->numerify('###'),
            'status' => EpisodeStatus::Open,
            'started_at' => now()->subHour(),
            'created_by' => $actor->id,
        ]);
        $share = bcsub('50000.00', $coverage, 2);

        return Invoice::create([
            'patient_id' => $patient->id,
            'episode_id' => $episode->id,
            'invoice_number' => fake()->unique()->bothify('AF-######'),
            'status' => $status,
            'currency' => 'MGA',
            'subtotal_amount' => '50000.00',
            'coverage_amount' => $coverage,
            'total_amount' => $share,
            'paid_amount' => '0.00',
            'balance_amount' => $share,
            'created_by' => $actor->id,
        ]);
    }

    private function settings(array $values): void
    {
        AppSetting::query()->updateOrCreate(['id' => 1], $values);
        app(AppSettings::class)->forget();
    }

    private function coupon(string $code, string $type, string $value, array $extra = []): DiscountCoupon
    {
        return DiscountCoupon::create(['code' => $code, 'discount_type' => $type, 'discount_value' => $value, ...$extra]);
    }

    /** Seuils VIP bas (un passage, 1 000 Ar encaissés sur douze mois), remise réglée avec eux. */
    private function vipSettings(array $values = []): void
    {
        PatientVipSetting::query()->delete();
        PatientVipSetting::query()->create([
            'enabled' => true,
            'min_episodes' => 1,
            'min_amount' => '1000.00',
            'window_months' => 12,
            ...$values,
        ]);
    }

    /** Un patient qui atteint les seuils de `vipSettings()` : un passage et 20 000 Ar déjà encaissés. */
    private function vipPatient(User $actor): Patient
    {
        $patient = $this->patient();
        $paid = $this->invoice($actor, $patient);
        $method = PaymentMethod::query()->firstOrCreate(['code' => 'CASH'], ['name' => 'Espèces', 'category' => 'CASH', 'active' => true]);
        $session = CashSession::query()->firstOrCreate(['session_number' => 'C-26-0001'], [
            'active_key' => 'SINGLE_OPEN_CASH',
            'status' => 'OPEN',
            'opening_amount' => 0,
            'opened_by' => $actor->id,
            'opened_at' => now()->subMonth(),
        ]);
        Payment::query()->create([
            'invoice_id' => $paid->id,
            'payment_method_id' => $method->id,
            'payment_number' => fake()->unique()->bothify('P-26-####'),
            'amount' => '20000.00',
            'currency' => 'MGA',
            'status' => 'COMPLETED',
            'paid_at' => now()->subDay(),
            'cash_session_id' => $session->id,
            'received_by' => $actor->id,
        ]);

        return $patient;
    }

    private function staffPatient(User $actor): Patient
    {
        $patient = $this->patient();
        $employee = Employee::query()->create([
            'employee_number' => fake()->unique()->bothify('EMP-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'sex' => 'F',
            'active' => true,
        ]);
        PatientStaffLink::query()->create(['patient_id' => $patient->id, 'employee_id' => $employee->id, 'linked_by' => $actor->id, 'linked_at' => now()]);

        return $patient;
    }

    public function test_a_patient_discount_reduces_the_patient_share_and_is_traced(): void
    {
        $cashier = $this->cashier();
        $patient = $this->patient();
        $approver = $this->userWith(['discounts.approve'], 'ADMINISTRATION');
        PatientDiscount::create(['patient_id' => $patient->id, 'discount_type' => 'PERCENT', 'discount_value' => '10', 'reason' => 'Convention', 'valid_from' => now()->toDateString(), 'created_by' => $approver->id]);
        $invoice = $this->invoice($cashier, $patient);

        $this->actingAs($cashier)->post("/invoices/{$invoice->uuid}/discount")->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('5000.00', (string) $invoice->discount_amount);
        $this->assertSame('45000.00', (string) $invoice->total_amount);
        $this->assertSame('45000.00', (string) $invoice->balance_amount);
        $discount = InvoiceDiscount::sole();
        $this->assertSame(DiscountSource::Patient, $discount->source);
        $this->assertSame($cashier->id, $discount->applied_by);
        $this->assertTrue(AuditLog::query()->where('action', 'billing.discount.apply')->exists());
    }

    public function test_the_discount_is_computed_on_the_patient_share_after_the_mutual_coverage(): void
    {
        $cashier = $this->cashier();
        $patient = $this->staffPatient($cashier);
        $this->settings(['staff_discount_type' => 'PERCENT', 'staff_discount_value' => '50']);
        // La mutuelle couvre 40 000 : la part patient est de 10 000.
        $invoice = $this->invoice($cashier, $patient, coverage: '40000.00');

        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier);

        $invoice->refresh();
        $this->assertSame('5000.00', (string) $invoice->discount_amount);
        $this->assertSame('5000.00', (string) $invoice->balance_amount);
        $this->assertSame('40000.00', (string) $invoice->coverage_amount, 'la mutuelle paie toujours sa part');
        $this->assertSame(DiscountSource::Staff, InvoiceDiscount::sole()->source);
    }

    public function test_only_the_most_advantageous_discount_is_applied_and_a_weaker_coupon_is_not_consumed(): void
    {
        $cashier = $this->cashier();
        $patient = $this->staffPatient($cashier);
        $this->settings(['staff_discount_type' => 'PERCENT', 'staff_discount_value' => '10']);
        $weak = $this->coupon('PETIT', 'AMOUNT', '1000');
        $strong = $this->coupon('FORT', 'PERCENT', '20');

        $first = $this->invoice($cashier, $patient);
        app(ApplyInvoiceDiscountAction::class)->execute($first, $cashier, 'petit');
        $this->assertSame(DiscountSource::Staff, $first->activeDiscount()->first()->source);
        $this->assertSame(0, $weak->refresh()->uses_count, 'un coupon moins avantageux n’est pas consommé');

        $second = $this->invoice($cashier, $patient);
        app(ApplyInvoiceDiscountAction::class)->execute($second, $cashier, ' fort ');
        $this->assertSame(DiscountSource::Coupon, $second->activeDiscount()->first()->source);
        $this->assertSame('10000.00', (string) $second->refresh()->discount_amount);
        $this->assertSame(1, $strong->refresh()->uses_count);
    }

    public function test_a_second_discount_is_refused_on_the_same_invoice(): void
    {
        $cashier = $this->cashier();
        $this->coupon('A1', 'PERCENT', '5');
        $invoice = $this->invoice($cashier);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'A1');

        $this->expectException(ValidationException::class);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'A1');
    }

    public function test_an_amount_never_goes_below_zero_and_a_zero_invoice_is_settled_without_payment(): void
    {
        $cashier = $this->cashier();
        $this->coupon('GRATUIT', 'AMOUNT', '80000');
        $invoice = $this->invoice($cashier);

        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'GRATUIT');

        $invoice->refresh();
        $this->assertSame('50000.00', (string) $invoice->discount_amount);
        $this->assertSame('0.00', (string) $invoice->balance_amount);
        $this->assertSame(InvoiceStatus::Covered, $invoice->status);
        $this->assertSame(0, $invoice->payments()->count(), 'aucun paiement n’est fabriqué');

        app(RemoveInvoiceDiscountAction::class)->execute($invoice, $cashier, 'Erreur de code');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Validated, $invoice->status);
        $this->assertSame('50000.00', (string) $invoice->balance_amount);
        $this->assertSame(0, DiscountCoupon::query()->where('code', 'GRATUIT')->value('uses_count'), 'le coupon retrouve son usage');
        $this->assertNotNull(InvoiceDiscount::sole()->removed_at, 'la remise retirée reste tracée');
    }

    public function test_a_draft_discounted_to_zero_is_validated_as_covered(): void
    {
        $cashier = $this->cashier();
        $validator = $this->userWith(['billing.validate']);
        $this->coupon('TOUT', 'PERCENT', '100');
        $invoice = $this->invoice($cashier, status: InvoiceStatus::Draft);
        $invoice->lines()->create(['description' => 'Consultation', 'quantity' => '1.00', 'unit_price' => '50000.00', 'line_total' => '50000.00', 'created_by' => $cashier->id]);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'TOUT');

        $validated = app(ValidateInvoiceAction::class)->execute($invoice, $validator);

        $this->assertSame(InvoiceStatus::Covered, $validated->status);
    }

    public function test_nothing_changes_once_a_payment_has_been_received(): void
    {
        $cashier = $this->cashier();
        $this->coupon('TARD', 'PERCENT', '10');
        $invoice = $this->invoice($cashier);
        $invoice->forceFill(['paid_amount' => '1000.00', 'balance_amount' => '49000.00', 'status' => InvoiceStatus::PartiallyPaid])->save();

        $this->expectException(ValidationException::class);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'TARD');
    }

    public function test_an_expired_archived_or_exhausted_coupon_is_refused_with_its_reason(): void
    {
        $cashier = $this->cashier();
        $this->coupon('VIEUX', 'PERCENT', '10', ['valid_until' => now()->subDay()->toDateString()]);
        $this->coupon('USE', 'PERCENT', '10', ['max_uses' => 1, 'uses_count' => 1]);
        $invoice = $this->invoice($cashier);

        foreach (['VIEUX' => 'expiré', 'USE' => 'nombre de fois', 'INCONNU' => 'Aucun coupon'] as $code => $reason) {
            try {
                app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, $code);
                $this->fail("{$code} aurait dû être refusé.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($reason, $exception->errors()['coupon_code'][0]);
            }
        }
    }

    public function test_the_cashier_needs_the_permission(): void
    {
        $clerk = $this->userWith(['billing.view']);
        $this->coupon('X', 'PERCENT', '10');
        $invoice = $this->invoice($clerk);

        $this->actingAs($clerk)->post("/invoices/{$invoice->uuid}/discount", ['coupon_code' => 'X'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $clerk, 'X');
    }

    public function test_the_offers_endpoint_lists_the_best_first_and_checks_a_coupon(): void
    {
        $cashier = $this->cashier();
        $patient = $this->staffPatient($cashier);
        $this->settings(['staff_discount_type' => 'AMOUNT', 'staff_discount_value' => '2000']);
        $this->coupon('DIX', 'PERCENT', '10');
        $invoice = $this->invoice($cashier, $patient);

        $this->actingAs($cashier)->getJson("/invoices/{$invoice->uuid}/discounts?coupon_code=dix")
            ->assertOk()
            ->assertJsonPath('discountable', true)
            ->assertJsonPath('offers.0.source', 'COUPON')
            ->assertJsonPath('offers.0.amount', '5000.00')
            ->assertJsonPath('offers.1.source', 'STAFF')
            ->assertJsonPath('coupon_error', null);

        $this->actingAs($cashier)->getJson("/invoices/{$invoice->uuid}/discounts?coupon_code=NON")
            ->assertOk()
            ->assertJsonPath('coupon_error', 'Aucun coupon ne porte ce code sur ce site.');
    }

    public function test_a_patient_discount_is_granted_and_cancelled_by_an_authorized_person_only(): void
    {
        $patient = $this->patient();
        $cashier = $this->cashier();
        $approver = $this->userWith(['discounts.approve', 'discounts.view'], 'ADMINISTRATION');

        $this->actingAs($cashier)->post("/patients/{$patient->uuid}/discounts", ['discount_type' => 'PERCENT', 'discount_value' => 10, 'reason' => 'Convention'])->assertForbidden();

        $this->actingAs($approver)->post("/patients/{$patient->uuid}/discounts", ['discount_type' => 'PERCENT', 'discount_value' => 120, 'reason' => 'Trop'])
            ->assertSessionHasErrors('discount_value');
        $this->actingAs($approver)->post("/patients/{$patient->uuid}/discounts", ['discount_type' => 'PERCENT', 'discount_value' => 15, 'reason' => 'Convention entreprise'])
            ->assertSessionHasNoErrors();

        $discount = PatientDiscount::sole();
        $this->assertTrue($discount->isInForce());
        $this->assertSame($approver->id, $discount->created_by);

        $this->actingAs($approver)->post("/patient-discounts/{$discount->uuid}/cancel", ['reason' => 'Fin de convention'])->assertSessionHasNoErrors();
        $this->assertFalse($discount->refresh()->isInForce());

        // Annulée, elle ne s'applique plus.
        $this->actingAs($cashier)->post('/invoices/'.$this->invoice($cashier, $patient)->uuid.'/discount')->assertSessionHasErrors('discount');
    }

    public function test_the_discount_rules_are_validated_where_they_are_set(): void
    {
        // La remise du personnel se règle avec les paramètres du site.
        $site = AppSettingsRules::settings();
        $base = [
            'currency_label' => 'Ar', 'currency_position' => 'after', 'currency_decimals' => 0,
            'baby_max_age' => 1, 'child_max_age' => 15,
        ];

        $this->assertTrue(validator([...$base, 'staff_discount_type' => 'PERCENT', 'staff_discount_value' => 150], $site)->fails());
        $this->assertTrue(validator([...$base, 'staff_discount_type' => 'PERCENT', 'staff_discount_value' => null], $site)->fails(), 'un type sans valeur');
        $this->assertFalse(validator([...$base, 'staff_discount_type' => 'AMOUNT', 'staff_discount_value' => 150000], $site)->fails());
        $this->assertFalse(validator([...$base, 'staff_discount_type' => null, 'staff_discount_value' => null], $site)->fails(), 'aucune remise');

        // La remise VIP, avec les seuils qui font un patient VIP (ADR-133).
        $vip = SitePatientVipSettingsController::rules();
        $thresholds = ['enabled' => true, 'min_episodes' => 5, 'min_amount' => 1000000, 'window_months' => 12];

        $this->assertTrue(validator([...$thresholds, 'discount_type' => 'PERCENT', 'discount_value' => 150], $vip)->fails());
        $this->assertTrue(validator([...$thresholds, 'discount_type' => 'PERCENT', 'discount_value' => null], $vip)->fails(), 'un type sans valeur');
        $this->assertTrue(validator([...$thresholds, 'discount_type' => 'GRATUIT', 'discount_value' => 10], $vip)->fails());
        $this->assertFalse(validator([...$thresholds, 'discount_type' => 'AMOUNT', 'discount_value' => 150000], $vip)->fails());
        $this->assertFalse(validator($thresholds, $vip)->fails(), 'aucune remise');
    }

    public function test_a_vip_patient_receives_the_discount_set_with_the_vip_thresholds(): void
    {
        $cashier = $this->cashier();
        $patient = $this->vipPatient($cashier);
        $this->vipSettings(['discount_type' => 'PERCENT', 'discount_value' => '10']);
        $invoice = $this->invoice($cashier, $patient);

        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier);

        $this->assertSame(DiscountSource::Vip, InvoiceDiscount::sole()->source);
        $this->assertSame('5000.00', (string) $invoice->refresh()->discount_amount);
    }

    public function test_no_vip_discount_without_a_discount_or_when_the_vip_category_is_disabled(): void
    {
        $cashier = $this->cashier();
        $patient = $this->vipPatient($cashier);

        // VIP, mais aucune remise réglée avec les seuils.
        $this->vipSettings();
        $this->assertSame([], app(InvoiceDiscountResolver::class)->offers($this->invoice($cashier, $patient)));

        // Une remise réglée, mais la catégorie désactivée : personne n'est VIP.
        $this->vipSettings(['enabled' => false, 'discount_type' => 'PERCENT', 'discount_value' => '10']);
        $this->assertSame([], app(InvoiceDiscountResolver::class)->offers($this->invoice($cashier, $patient)));

        // Et un patient qui n'atteint pas les seuils n'y a pas droit.
        $this->vipSettings(['discount_type' => 'PERCENT', 'discount_value' => '10']);
        $this->assertSame([], app(InvoiceDiscountResolver::class)->offers($this->invoice($cashier)));
    }

    public function test_an_added_line_recomputes_a_percentage_discount(): void
    {
        $cashier = $this->cashier();
        $this->coupon('DIX', 'PERCENT', '10');
        $invoice = $this->invoice($cashier);
        app(ApplyInvoiceDiscountAction::class)->execute($invoice, $cashier, 'DIX');

        // La part patient passe à 80 000 (une ligne ajoutée tant que rien n'est payé).
        $invoice->forceFill(['subtotal_amount' => '80000.00'])->save();
        InvoiceDiscountTotals::refresh($invoice);

        $invoice->refresh();
        $this->assertSame('8000.00', (string) $invoice->discount_amount);
        $this->assertSame('72000.00', (string) $invoice->balance_amount);
    }
}
