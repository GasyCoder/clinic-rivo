<?php

namespace Tests\Feature\Administration;

use App\Enums\PaymentMethodCategory;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the tenders themselves is Super-Admin-only, through each site's
 * API — see tests/Feature/Api/SuperAdminSiteApiTest.php. What is left here is
 * the reference data this site starts from.
 */
class PaymentMethodSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_tender_per_mobile_money_operator_under_a_shared_category(): void
    {
        (new PaymentMethodSeeder)->run();

        $operators = PaymentMethod::query()
            ->where('category', PaymentMethodCategory::MobileMoney->value)
            ->orderBy('code')
            ->pluck('name', 'code');

        // In Madagascar mobile money is never one tender: each operator is
        // reconciled on its own account, so each is its own method.
        $this->assertSame([
            'MOBILE_MONEY_AIRTEL' => 'Airtel Money',
            'MOBILE_MONEY_MVOLA' => 'MVola',
            'MOBILE_MONEY_ORANGE' => 'Orange Money',
        ], $operators->all());

        $this->assertSame(
            PaymentMethodCategory::Cash,
            PaymentMethod::query()->where('code', 'CASH')->sole()->category,
        );
        $this->assertTrue(PaymentMethod::query()->where('code', 'CASH')->sole()->affects_cash_balance);
        // Only cash physically lands in the drawer.
        $this->assertSame(1, PaymentMethod::query()->where('affects_cash_balance', true)->count());
    }

    public function test_it_drops_the_never_used_generic_mobile_money_in_favour_of_the_operators(): void
    {
        PaymentMethod::query()->create([
            'code' => 'MOBILE_MONEY',
            'name' => 'Mobile money',
            'category' => PaymentMethodCategory::MobileMoney->value,
            'active' => true,
            'affects_cash_balance' => false,
        ]);

        (new PaymentMethodSeeder)->run();

        // The generic row would sit next to its own category and mean nothing.
        // It never served here, so it goes; one that had collected a payment
        // would be kept and only deactivated (ADR-010).
        $this->assertDatabaseMissing('payment_methods', ['code' => 'MOBILE_MONEY']);
        $this->assertDatabaseHas('payment_methods', ['code' => 'MOBILE_MONEY_MVOLA', 'active' => true]);
    }

    public function test_it_is_idempotent(): void
    {
        (new PaymentMethodSeeder)->run();
        $first = PaymentMethod::query()->count();

        (new PaymentMethodSeeder)->run();

        $this->assertSame($first, PaymentMethod::query()->count());
    }
}
