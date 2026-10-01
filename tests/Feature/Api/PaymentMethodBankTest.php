<?php

namespace Tests\Feature\Api;

use App\Models\Bank;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-239 — un mode « Banque » désigne une banque du référentiel du site ;
 * un mode « Autre » nomme sa catégorie. Les modes génériques déjà en service
 * restent valides sans qu'on leur invente une banque ou une précision.
 */
class PaymentMethodBankTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['payment_methods.view', 'payment_methods.create', 'payment_methods.update'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    public function test_a_bank_method_names_a_bank_of_the_referential_and_takes_its_label(): void
    {
        $boa = Bank::query()->where('code', 'BOA')->firstOrFail();

        $this->api()->getJson('/api/v1/super-admin/payment-methods')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $boa->uuid, 'code' => 'BOA']);

        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'BANK_BOA', 'name' => null, 'category' => 'BANK', 'bank_uuid' => $boa->uuid,
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', "BOA — {$boa->name}")
            ->assertJsonPath('data.bank.code', 'BOA')
            ->assertJsonPath('data.category_detail', null);

        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'CHEQUE_BOA', 'name' => 'Chèque BOA', 'category' => 'BANK', 'bank_uuid' => $boa->uuid,
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertCreated()->assertJsonPath('data.name', 'Chèque BOA');

        $this->assertSame($boa->id, PaymentMethod::query()->where('code', 'CHEQUE_BOA')->value('bank_id'));
        $this->assertTrue($boa->fresh()->isForceDeleteProtected(), 'Une banque désignée par un mode ne se détruit pas.');
    }

    public function test_a_new_bank_method_requires_an_active_bank_and_other_categories_carry_none(): void
    {
        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'BANK_X', 'name' => 'Virement', 'category' => 'BANK',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_uuid');

        $sbm = Bank::query()->where('code', 'SBM')->firstOrFail();
        $sbm->update(['active' => false]);
        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'BANK_SBM', 'category' => 'BANK', 'bank_uuid' => $sbm->uuid,
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_uuid');

        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'CASH', 'name' => 'Espèces', 'category' => 'CASH',
            'bank_uuid' => Bank::query()->where('code', 'BOA')->value('uuid'),
            'affects_cash_balance' => true, 'requires_reference' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_uuid');
    }

    public function test_an_other_method_names_its_category(): void
    {
        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'TPE', 'name' => 'Carte au TPE', 'category' => 'OTHER',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_detail');

        $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'TPE', 'name' => 'Carte au TPE', 'category' => 'OTHER', 'category_detail' => '  Carte   bancaire ',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertCreated()->assertJsonPath('data.category_detail', 'Carte bancaire');
    }

    public function test_generic_methods_already_in_service_stay_valid_without_invented_data(): void
    {
        $check = PaymentMethod::query()->create(['code' => 'CHECK', 'name' => 'Chèque', 'category' => 'BANK', 'active' => true, 'affects_cash_balance' => false]);
        $other = PaymentMethod::query()->create(['code' => 'OTHER', 'name' => 'Autre', 'category' => 'OTHER', 'active' => true, 'affects_cash_balance' => false]);

        $this->api()->putJson("/api/v1/super-admin/payment-methods/{$check->uuid}", [
            'name' => 'Chèque (toutes banques)', 'category' => 'BANK',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Chèque (toutes banques)')->assertJsonPath('data.bank', null);

        $this->api()->putJson("/api/v1/super-admin/payment-methods/{$other->uuid}", [
            'name' => 'Autre', 'category' => 'OTHER',
            'affects_cash_balance' => false, 'requires_reference' => false,
        ])->assertOk()->assertJsonPath('data.category_detail', null);
    }

    public function test_an_archived_bank_stays_on_the_method_that_already_names_it(): void
    {
        $bni = Bank::query()->where('code', 'BNI')->firstOrFail();
        $method = $this->api()->postJson('/api/v1/super-admin/payment-methods', [
            'code' => 'BANK_BNI', 'category' => 'BANK', 'bank_uuid' => $bni->uuid,
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertCreated()->json('data.uuid');

        $bni->update(['active' => false]);

        $this->api()->putJson("/api/v1/super-admin/payment-methods/{$method}", [
            'name' => 'Virement BNI', 'category' => 'BANK', 'bank_uuid' => $bni->uuid,
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertOk()->assertJsonPath('data.bank.archived', true);
    }

    private function api(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', self::PERMISSIONS),
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }
}
