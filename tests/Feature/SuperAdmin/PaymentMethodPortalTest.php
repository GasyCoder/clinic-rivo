<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-239 — le portail ne relaie au site que les champs de la catégorie
 * choisie : une banque pour « Banque », une précision pour « Autre ».
 */
class PaymentMethodPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
    }

    public function test_a_bank_method_is_relayed_with_its_bank_and_no_detail(): void
    {
        Http::fake(['a.test/*' => Http::response(['message' => 'ok', 'data' => []], 201)]);
        $bank = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

        $this->actingAs($this->superAdmin)->post('/super-admin/payment-methods', [
            'site_code' => 'A', 'code' => 'BANK_BOA', 'name' => '', 'category' => 'BANK',
            'bank_uuid' => $bank, 'category_detail' => 'ignorée',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://a.test/api/v1/super-admin/payment-methods'
            && $request['bank_uuid'] === $bank
            && ! array_key_exists('category_detail', $request->data())
            && $request['code'] === 'BANK_BOA');
    }

    public function test_an_other_method_is_relayed_with_its_detail_and_no_bank(): void
    {
        Http::fake(['a.test/*' => Http::response(['message' => 'ok', 'data' => []])]);

        $this->actingAs($this->superAdmin)->put('/super-admin/payment-methods/A/5b3d2c1a-0f9e-4d8c-b7a6-958473625140', [
            'name' => 'Carte au TPE', 'category' => 'OTHER', 'category_detail' => 'Carte bancaire',
            'bank_uuid' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['category_detail'] === 'Carte bancaire'
            && ! array_key_exists('bank_uuid', $request->data()));
    }

    public function test_the_label_is_required_except_for_a_bank_method(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->from('/super-admin/payment-methods')->post('/super-admin/payment-methods', [
            'site_code' => 'A', 'code' => 'MVOLA', 'name' => '', 'category' => 'MOBILE_MONEY',
            'affects_cash_balance' => false, 'requires_reference' => true,
        ])->assertSessionHasErrors('name');

        Http::assertNothingSent();
    }
}
