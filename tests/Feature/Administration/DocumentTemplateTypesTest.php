<?php

namespace Tests\Feature\Administration;

use App\Enums\DocumentDataContext;
use App\Enums\HrReferenceType;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** ADR-244 — un modèle de contrat ou de congé vise des types précis, sinon il est général. */
class DocumentTemplateTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_portal_saves_the_targeted_types_and_refuses_an_unknown_one(): void
    {
        $this->withHeaders($this->headers(['document_templates.view']))
            ->getJson('/api/v1/super-admin/document-templates/type-options')
            ->assertOk()
            ->assertJsonFragment(['code' => 'CDI'])
            ->assertJsonFragment(['code' => 'SICK_LEAVE']);

        $this->withHeaders($this->headers(['document_templates.create']))
            ->postJson('/api/v1/super-admin/document-templates', $this->payload(['applies_to' => ['cdi', 'CDI']]))
            ->assertCreated()
            ->assertJsonPath('data.applies_to', ['CDI'])
            ->assertJsonPath('data.applies_to_labels', ['CDI']);

        $this->withHeaders($this->headers(['document_templates.create']))
            ->postJson('/api/v1/super-admin/document-templates', $this->payload(['applies_to' => ['SICK_LEAVE']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('applies_to');

        // Omis à la modification : les types restent.
        $template = DocumentTemplate::query()->sole();
        $payload = $this->payload(['name' => 'Contrat CDI renommé']);
        unset($payload['applies_to']);
        $this->withHeaders($this->headers(['document_templates.update']))
            ->putJson("/api/v1/super-admin/document-templates/{$template->uuid}", $payload)
            ->assertOk()
            ->assertJsonPath('data.applies_to', ['CDI']);
    }

    public function test_printing_a_contract_opens_the_template_of_its_type_before_a_general_one(): void
    {
        $cdi = $this->template('Contrat CDI', ['CDI']);
        $this->template('Contrat CDD', ['CDD']);
        $this->template('Contrat général', null);
        $employee = $this->employee();

        $this->actingAs($this->administration)->get("/administration/contracts/{$this->contract($employee, 'CDI')->uuid}/print")
            ->assertRedirect()
            ->assertRedirectContains('template='.$cdi->uuid);

        // Aucun modèle pour un consultant : le général.
        $this->actingAs($this->administration)->get("/administration/contracts/{$this->contract($employee, 'CONSULTANT', '2027-01-01')->uuid}/print?choisir=1")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('templates', 1)->where('templates.0.name', 'Contrat général'));
    }

    public function test_a_template_reserved_to_cdi_refuses_to_generate_a_cdd_document(): void
    {
        $cdi = $this->template('Contrat CDI', ['CDI']);
        $employee = $this->employee();
        $cdd = $this->contract($employee, 'CDD');

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $cdi->uuid,
            'employee_uuid' => $employee->uuid,
            'employment_contract_uuid' => $cdd->uuid,
        ])->assertSessionHasErrors('document_template_uuid');

        $this->assertDatabaseCount('generated_documents', 0);
    }

    private function template(string $name, ?array $appliesTo): DocumentTemplate
    {
        return DocumentTemplate::query()->create([
            'lineage_id' => (string) Str::uuid(),
            'document_type' => 'CONTRAT',
            'data_context' => DocumentDataContext::EmployeeAndContract,
            'applies_to' => $appliesTo,
            'name' => $name,
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>Contrat</p>',
            'active' => true,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'TYP-'.Str::random(6), 'first_name' => 'Soa', 'last_name' => 'Rabe', 'sex' => 'F', 'active' => true,
        ]);
    }

    private function contract(Employee $employee, string $code, string $startsOn = '2026-01-01'): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => HrReferenceValue::query()->ofType(HrReferenceType::ContractType)->where('code', $code)->value('id'),
            'starts_on' => $startsOn,
            'ends_on' => $code === 'CDD' || $startsOn === '2026-01-01' ? '2026-06-30' : null,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'document_type' => 'CONTRAT',
            'data_context' => 'EMPLOYEE_AND_CONTRACT',
            'applies_to' => [],
            'name' => 'Contrat CDI',
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>Contrat de travail.</p>',
            ...$overrides,
        ];
    }

    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
