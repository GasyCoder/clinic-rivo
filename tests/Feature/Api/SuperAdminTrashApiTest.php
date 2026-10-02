<?php

namespace Tests\Feature\Api;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Enums\SupplierCatalogFileKind;
use App\Models\AddressEntry;
use App\Models\CatalogItem;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SupplierCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminTrashApiTest extends TestCase
{
    use RefreshDatabase;

    private User $localActor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $role = Role::query()->create(['code' => 'ADMINISTRATION', 'name' => 'Administration']);
        $this->localActor = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_trash_lists_only_supported_archived_records_with_filters_and_actor_information(): void
    {
        $patient = $this->patient('PA-000001', 'Rakoto', 'Soa');
        $patient->delete_reason = 'Dossier créé en double à la réception';
        $patient->delete();

        $address = AddressEntry::query()->create(['label' => 'Ancienne adresse', 'active' => true]);
        $address->delete_reason = 'Libellé obsolète après vérification';
        $address->delete();

        $this->withHeaders($this->headers(['trash.view']))
            ->getJson('/api/v1/super-admin/trash?search=Rakoto&category=PATIENT')
            ->assertOk()
            ->assertJsonPath('meta.site.code', 'A')
            ->assertJsonPath('meta.summary.total', 1)
            ->assertJsonPath('meta.summary.categories.PATIENT', 1)
            ->assertJsonPath('meta.summary.categories.ADDRESS_ENTRY', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'PATIENT')
            ->assertJsonPath('data.0.reference', 'PA-000001')
            ->assertJsonPath('data.0.delete_reason', 'Dossier créé en double à la réception')
            ->assertJsonPath('data.0.deleted_by', 'Système');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/super-admin/trash')
            ->assertForbidden();
    }

    public function test_patient_restore_requires_trash_and_category_permissions_and_is_audited(): void
    {
        $patient = $this->patient('PA-000002', 'Rabe', 'Kanto');
        $patient->delete_reason = 'Dossier archivé à tort après contrôle';
        $patient->delete();

        $this->withHeaders($this->headers(['trash.restore']))
            ->postJson("/api/v1/super-admin/trash/PATIENT/{$patient->uuid}/restore")
            ->assertForbidden();
        $this->assertSoftDeleted('patients', ['uuid' => $patient->uuid]);

        $actorUuid = (string) Str::uuid();
        $this->withHeaders($this->headers(['trash.restore', 'patients.restore'], $actorUuid))
            ->postJson("/api/v1/super-admin/trash/PATIENT/{$patient->uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.category', 'PATIENT')
            ->assertJsonPath('data.already_restored', false);

        $this->assertNotSoftDeleted('patients', ['uuid' => $patient->uuid]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_uuid' => $patient->uuid,
            'external_actor_uuid' => $actorUuid,
            'external_actor_name' => 'Direction centrale',
        ]);

        $this->withHeaders($this->headers(['trash.restore', 'patients.restore'], $actorUuid))
            ->postJson("/api/v1/super-admin/trash/PATIENT/{$patient->uuid}/restore")
            ->assertOk()
            ->assertJsonPath('data.already_restored', true);
    }

    public function test_catalog_restore_keeps_its_domain_permission_check(): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'CONS-001',
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'created_by' => $this->localActor->id,
            'updated_by' => $this->localActor->id,
        ]);
        $item->delete_reason = 'Prestation temporairement retirée du catalogue';
        $item->delete();

        $this->withHeaders($this->headers(['trash.restore', 'catalog.items.restore']))
            ->postJson("/api/v1/super-admin/trash/CATALOG_ITEM/{$item->uuid}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('catalog_items', ['uuid' => $item->uuid]);
    }

    private function patient(string $number, string $lastName, string $firstName): Patient
    {
        return Patient::query()->create([
            'patient_number' => $number,
            'patient_type' => PatientType::Standard,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => '1990-01-01',
            'sex' => PatientSex::Male,
        ]);
    }

    /** @param array<int, string> $permissions */
    public function test_emptying_the_trash_destroys_only_what_never_served_and_reports_the_rest(): void
    {
        $unused = AddressEntry::query()->create(['label' => 'Adresse en double', 'active' => true]);
        $unused->delete_reason = 'Doublon';
        $unused->delete();

        $used = AddressEntry::query()->create(['label' => 'Adresse habitée', 'active' => true]);
        $this->patient('PA-000009', 'Rasoa', 'Vola')->forceFill(['address_entry_id' => $used->id])->save();
        $used->delete_reason = 'Renommée';
        $used->delete();

        $this->withHeaders($this->headers(['trash.force_delete']))
            ->deleteJson('/api/v1/super-admin/trash', ['category' => 'ADDRESS_ENTRY'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 0)
            ->assertJsonPath('data.skipped', ['Adresses']);

        $this->withHeaders($this->headers(['trash.force_delete', 'address_entries.restore']))
            ->deleteJson('/api/v1/super-admin/trash', ['category' => 'ALL'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.kept', 1)
            ->assertJsonPath('data.kept_items.0.title', 'Adresse habitée');

        $this->assertNull(AddressEntry::withTrashed()->find($unused->id));
        $this->assertNotNull(AddressEntry::withTrashed()->find($used->id));

        $this->withHeaders($this->headers())
            ->deleteJson('/api/v1/super-admin/trash')
            ->assertForbidden();
    }

    public function test_archived_employees_and_contracts_appear_in_the_trash_and_empty_in_order(): void
    {
        $employee = Employee::query()->create(['employee_number' => 'EMP-9', 'last_name' => 'Doublon', 'sex' => 'F', 'active' => true]);
        $type = HrReferenceValue::query()->create(['type' => 'CONTRACT_TYPE', 'code' => 'CDD', 'label' => 'CDD', 'active' => true]);
        $contract = EmploymentContract::query()->create(['employee_id' => $employee->id, 'contract_type_id' => $type->id, 'starts_on' => '2026-01-01']);
        $contract->delete_reason = 'Saisi deux fois';
        $contract->delete();
        $employee->delete_reason = 'Doublon';
        $employee->delete();

        $this->withHeaders($this->headers(['trash.view']))
            ->getJson('/api/v1/super-admin/trash?category=EMPLOYEE')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'EMP-9')
            // ADR-243 — un contrat qui n'a produit aucun document part avec le dossier.
            ->assertJsonPath('data.0.can_force_delete', true)
            ->assertJsonPath('data.0.force_delete_blockers', []);

        // Le contrat part d'abord, puis le dossier qui n'est plus désigné par rien.
        $this->withHeaders($this->headers(['trash.force_delete', 'contracts.restore', 'employees.force_delete']))
            ->deleteJson('/api/v1/super-admin/trash', ['category' => 'ALL'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertNull(Employee::withTrashed()->find($employee->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.force_delete']);
    }

    public function test_a_supplier_catalog_that_was_imported_but_never_used_can_be_destroyed(): void
    {
        $supplier = MedicineSupplier::query()->create(['code' => 'PHL', 'name' => 'Pharmalife', 'created_by' => $this->localActor->id]);
        $catalog = fn (string $name) => $supplier->catalogs()->create([
            'original_name' => $name, 'path' => "suppliers/{$supplier->uuid}/{$name}", 'mime_type' => 'application/vnd.ms-excel',
            'size' => 10, 'kind' => SupplierCatalogFileKind::Excel, 'imported_at' => now(), 'created_by' => $this->localActor->id,
        ]);
        $unused = $catalog('jamais-repris.xlsx');
        $unused->items()->create(['reference' => 'A-1', 'medicine_label' => 'Zinc 20 mg', 'row_number' => 1]);
        $used = $catalog('repris.xlsx');
        $item = CatalogItem::query()->create(['code' => 'PH-0001', 'name' => 'Amoxicilline', 'type' => CatalogItemType::Medicine, 'module' => CatalogModule::Pharmacy, 'unit' => 'boîte', 'billable' => false, 'stockable' => true, 'created_by' => $this->localActor->id, 'updated_by' => $this->localActor->id]);
        $medicine = Medicine::query()->create(['catalog_item_id' => $item->id, 'generic_name' => 'Amoxicilline', 'form' => MedicineForm::Tablet, 'strength' => '500 mg', 'active' => true, 'created_by' => $this->localActor->id, 'updated_by' => $this->localActor->id]);
        $used->items()->create(['reference' => 'B-1', 'medicine_label' => 'Amoxicilline', 'row_number' => 1, 'linked_medicine_id' => $medicine->id]);
        foreach ([$unused, $used] as $file) {
            $file->delete_reason = 'Ancien tarif';
            $file->delete();
        }

        $this->withHeaders($this->headers(['trash.view']))
            ->getJson('/api/v1/super-admin/trash?category=SUPPLIER_CATALOG')
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('data', fn ($rows) => collect($rows)->firstWhere('title', 'repris.xlsx')['force_delete_blockers'] === ['1 ligne rattachée à un médicament']
                && collect($rows)->firstWhere('title', 'jamais-repris.xlsx')['can_force_delete'] === true)->etc());

        $this->withHeaders($this->headers(['trash.force_delete', 'supplier_catalogs.restore']))
            ->deleteJson('/api/v1/super-admin/trash', ['category' => 'SUPPLIER_CATALOG'])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.kept', 1);

        $this->assertNull(SupplierCatalog::withTrashed()->find($unused->id));
        $this->assertDatabaseMissing('supplier_catalog_items', ['reference' => 'A-1']);
        $this->assertNotNull(SupplierCatalog::withTrashed()->find($used->id));
    }

    private function headers(array $permissions = [], ?string $actorUuid = null): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
