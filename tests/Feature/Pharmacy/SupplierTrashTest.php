<?php

namespace Tests\Feature\Pharmacy;

use App\Enums\TrashCategory;
use App\Models\MedicineSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Trash\TrashDirectory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Un fournisseur supprimé rejoint la corbeille (ADR-009) et peut en revenir.
 * La suppression définitive reste étroite : refusée dès que quoi que ce soit
 * a utilisé le dossier (ADR-010).
 */
class SupplierTrashTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        (new PermissionSeeder)->run();
        $role = Role::query()->create(['code' => 'TRASH_TEST', 'name' => 'Corbeille (test)']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_an_archived_supplier_appears_in_the_trash_and_can_be_restored(): void
    {
        $user = $this->actor(['trash.view', 'trash.restore', 'medicine_suppliers.restore']);
        $supplier = MedicineSupplier::query()->create(['code' => 'FOUR-01', 'name' => 'Centrale Pharmaceutique']);
        $supplier->delete_reason = 'Fournisseur remplacé';
        $supplier->delete();

        $trash = app(TrashDirectory::class);
        $listed = collect($trash->list(['category' => TrashCategory::MedicineSupplier->value])['data']);

        $this->assertSame(['Centrale Pharmaceutique'], $listed->pluck('title')->all());
        $this->assertSame('Fournisseur remplacé', $listed->first()['delete_reason']);

        $trash->restore(TrashCategory::MedicineSupplier, $supplier->uuid, CatalogActor::fromUser($user));

        $this->assertFalse(MedicineSupplier::withTrashed()->findOrFail($supplier->id)->trashed());
    }

    public function test_permanently_deleting_carries_away_the_folder_but_never_its_history(): void
    {
        $user = $this->actor(['trash.view', 'trash.force_delete', 'medicine_suppliers.restore']);
        $withCatalog = MedicineSupplier::query()->create(['code' => 'FOUR-02', 'name' => 'Jamais servi']);
        $used = MedicineSupplier::query()->create(['code' => 'FOUR-03', 'name' => 'A déjà facturé']);

        // Un catalogue appartient au dossier : il ne bloque pas, il suit.
        Storage::disk('local')->put('suppliers/tarif.xlsx', 'contenu');
        $catalog = $withCatalog->catalogs()->create([
            'original_name' => 'Tarif 2026.xlsx',
            'path' => 'suppliers/tarif.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => 7,
            'kind' => 'EXCEL',
        ]);
        $catalog->items()->create(['row_number' => 2, 'medicine_label' => 'Alcool 90°', 'supplier_price' => null]);

        // Une facture, elle, est de l'histoire comptable.
        $used->invoices()->create([
            'invoice_number' => 'FAC-2026-001',
            'invoice_date' => now()->toDateString(),
            'total_amount' => '250000',
            'currency' => 'MGA',
        ]);

        $withCatalog->delete();
        $used->delete();

        $trash = app(TrashDirectory::class);
        $actor = CatalogActor::fromUser($user);

        try {
            $trash->forceDelete(TrashCategory::MedicineSupplier, $used->uuid, $actor);
            $this->fail('La suppression définitive aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('déjà servi', $exception->getMessage());
        }

        $this->assertNotNull(MedicineSupplier::withTrashed()->find($used->id));

        $trash->forceDelete(TrashCategory::MedicineSupplier, $withCatalog->uuid, $actor);

        $this->assertNull(MedicineSupplier::withTrashed()->find($withCatalog->id));
        $this->assertSame(0, SupplierCatalog::withTrashed()->where('medicine_supplier_id', $withCatalog->id)->count());
        $this->assertSame(0, SupplierCatalogItem::query()->where('supplier_catalog_id', $catalog->id)->count());
        $this->assertFalse(Storage::disk('local')->exists('suppliers/tarif.xlsx'));
    }

    /**
     * ADR-061 — la liste dit avant le clic ce qui retient un élément : un
     * bouton qui serait refusé ne doit pas être proposé.
     */
    public function test_the_trash_says_which_items_can_be_destroyed_and_what_holds_the_others(): void
    {
        $user = $this->actor(['trash.view', 'trash.force_delete', 'medicine_suppliers.restore']);
        $free = MedicineSupplier::query()->create(['code' => 'FOUR-04', 'name' => 'Jamais servi']);
        $used = MedicineSupplier::query()->create(['code' => 'FOUR-05', 'name' => 'A déjà facturé']);
        $used->invoices()->create([
            'invoice_number' => 'FAC-2026-009',
            'invoice_date' => now()->toDateString(),
            'total_amount' => '120000',
            'currency' => 'MGA',
        ]);
        $free->delete();
        $used->delete();

        $records = collect(app(TrashDirectory::class)->list(['category' => TrashCategory::MedicineSupplier->value])['data'])
            ->keyBy('uuid');

        $this->assertTrue($records[$free->uuid]['can_force_delete']);
        $this->assertSame([], $records[$free->uuid]['force_delete_blockers']);

        $this->assertFalse($records[$used->uuid]['can_force_delete']);
        $this->assertSame(['1 facture'], $records[$used->uuid]['force_delete_blockers']);

        // Ce que l'écran annonce est ce que le serveur applique.
        $this->expectException(ValidationException::class);
        app(TrashDirectory::class)->forceDelete(TrashCategory::MedicineSupplier, $used->uuid, CatalogActor::fromUser($user));
    }
}
