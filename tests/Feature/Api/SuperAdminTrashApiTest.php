<?php

namespace Tests\Feature\Api;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Models\AddressEntry;
use App\Models\CatalogItem;
use App\Models\Patient;
use App\Models\Role;
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
