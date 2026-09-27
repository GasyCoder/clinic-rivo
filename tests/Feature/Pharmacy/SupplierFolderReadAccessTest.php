<?php

namespace Tests\Feature\Pharmacy;

use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-098 — « Voir les fournisseurs » opens a supplier folder in read-only
 * mode: its catalogs, orders, invoices and prices. Writing, and the global
 * purchase lists, keep their own permissions.
 */
class SupplierFolderReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_suppliers_opens_the_whole_folder_read_only(): void
    {
        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'M', 'rivo.site.name' => 'Mampikony']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        $role = Role::query()->create(['code' => 'READER', 'name' => 'Lecteur']);
        $role->permissions()->attach(Permission::query()->whereIn('name', ['pharmacy.view', 'medicine_suppliers.view'])->pluck('id'));
        $reader = User::factory()->create(['role_id' => $role->id]);
        $supplier = MedicineSupplier::query()->create(['code' => 'PHARMADIS', 'name' => 'Pharmadis']);

        $this->actingAs($reader)->get("/pharmacy/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_catalogs', true)
                ->where('can.view_orders', true)
                ->where('can.view_invoices', true)
                ->where('can.view_offers', true)
                ->where('can.create_order', false));

        $this->get("/pharmacy/suppliers/{$supplier->uuid}/catalogs")->assertOk();
        $this->get("/pharmacy/suppliers/{$supplier->uuid}/products")->assertOk();
        $this->get("/pharmacy/purchase-orders?supplier={$supplier->uuid}")->assertOk();
        $this->get("/pharmacy/supplier-invoices?supplier={$supplier->uuid}")->assertOk();

        // The purchase lists of every supplier, and any write, stay closed.
        $this->get('/pharmacy/purchase-orders')->assertForbidden();
        $this->get('/pharmacy/supplier-invoices')->assertForbidden();
        $this->get('/pharmacy/purchase-orders/create')->assertForbidden();
    }
}
