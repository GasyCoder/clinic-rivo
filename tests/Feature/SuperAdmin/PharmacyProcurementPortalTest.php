<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-098 — the portal forwards orders and supplier invoices to the site's
 * API and shows back its answer. Receiving goods is never offered from here.
 */
class PharmacyProcurementPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private const FOLDER = '/super-admin/pharmacy-suppliers/A/supplier-a';

    private const API = 'https://a.test/api/v1/super-admin/pharmacy/suppliers/supplier-a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_a_basket_spanning_two_suppliers_creates_one_draft_order_per_supplier(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/pharmacy/suppliers/*/orders' => Http::response([
            'message' => 'Commande créée en brouillon.',
            'data' => ['uuid' => 'order-10', 'order_number' => 'BC-000010'],
        ], 201)]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/pharmacy-suppliers/A/commander', [
                'orders' => [
                    ['supplier_uuid' => '11111111-1111-4111-8111-111111111111', 'lines' => [['medicine_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 10, 'unit_price' => '100']]],
                    ['supplier_uuid' => '33333333-3333-4333-8333-333333333333', 'lines' => [['medicine_uuid' => '44444444-4444-4444-8444-444444444444', 'quantity' => 5, 'unit_price' => '250']]],
                ],
            ])
            ->assertRedirect('/super-admin/pharmacy-suppliers?site=A')
            ->assertSessionHas('status', '2 commandes créées en brouillon, une par fournisseur.');

        // One command per supplier: a purchase order never mixes two folders.
        Http::assertSentCount(2);
    }

    public function test_an_order_is_forwarded_to_the_site_then_opens_on_its_own_page(): void
    {
        Http::fake([self::API.'/orders' => Http::response([
            'message' => 'Commande BC-000009 créée en brouillon.',
            'data' => ['uuid' => 'order-9', 'order_number' => 'BC-000009'],
        ], 201)]);

        $this->actingAs($this->superAdmin)
            ->post(self::FOLDER.'/orders', ['notes' => 'Réassort', 'lines' => [
                ['medicine_uuid' => 'medicine-1', 'quantity_ordered' => 5, 'unit_price' => '120'],
            ]])
            ->assertRedirect(self::FOLDER.'/orders/order-9')
            ->assertSessionHas('status', 'Commande BC-000009 créée en brouillon.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::API.'/orders'
            && $request['lines'][0]['quantity_ordered'] === 5
            && $request->hasHeader('Idempotency-Key'));
    }

    public function test_a_refusal_from_the_site_comes_back_on_the_order_form(): void
    {
        Http::fake([self::API.'/orders' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['lines.0.unit_price' => ['Le prix unitaire doit être supérieur à 0.']],
        ], 422)]);

        $this->actingAs($this->superAdmin)
            ->from(self::FOLDER.'/orders/create')
            ->post(self::FOLDER.'/orders', ['lines' => [['medicine_uuid' => 'medicine-1', 'quantity_ordered' => 5, 'unit_price' => '0']]])
            ->assertRedirect(self::FOLDER.'/orders/create')
            ->assertSessionHasErrors(['lines.0.unit_price' => 'Le prix unitaire doit être supérieur à 0.']);
    }

    public function test_the_order_page_offers_its_actions_but_never_the_reception(): void
    {
        Http::fake([self::API.'/orders/order-9' => Http::response(['data' => [
            'supplier' => ['uuid' => 'supplier-a', 'name' => 'Centrale', 'archived' => false],
            'order' => [
                'uuid' => 'order-9', 'order_number' => 'BC-000009', 'status' => 'ORDERED', 'status_label' => 'Commandée',
                'supplier' => 'Centrale', 'supplier_uuid' => 'supplier-a', 'total_amount' => '600.00',
                'lines' => [], 'receipts' => [], 'invoices' => [],
            ],
        ]])]);

        $this->actingAs($this->superAdmin)->get(self::FOLDER.'/orders/order-9')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/PharmacySuppliers/OrderShow')
                ->where('order.order_number', 'BC-000009')
                ->where('can.submit', true)
                ->where('can.cancel', true)
                ->missing('can.receive'));
    }

    public function test_an_invoice_and_its_document_are_forwarded_as_one_multipart_request(): void
    {
        Http::fake([self::API.'/invoices' => Http::response([
            'message' => 'Facture FAC-1 enregistrée.',
            'data' => ['uuid' => 'invoice-1', 'invoice_number' => 'FAC-1'],
        ], 201)]);

        $this->actingAs($this->superAdmin)
            ->post(self::FOLDER.'/invoices', [
                'invoice_number' => 'FAC-1',
                'invoice_date' => '2026-09-14',
                'lines' => [['medicine_uuid' => 'medicine-1', 'description' => 'Amoxicilline', 'quantity' => 2, 'unit_price' => '300']],
                'attachment' => UploadedFile::fake()->create('facture.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect(self::FOLDER.'/invoices/invoice-1');

        Http::assertSent(function ($request) {
            // A multipart body is a list of named parts, not a keyed array.
            $part = fn (string $name) => collect($request->data())->firstWhere('name', $name)['contents'] ?? null;

            return $request->isMultipart()
                && $request->hasFile('attachment')
                && $part('invoice_number') === 'FAC-1'
                && (json_decode((string) $part('lines'), true)[0]['quantity'] ?? null) == 2;
        });
    }
}
