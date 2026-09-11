<?php

namespace Tests\Feature\Administration;

use App\Enums\DocumentDataContext;
use App\Enums\HrReferenceType;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
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

class GeneratedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = $this->userWithRole('ADMINISTRATION');
    }

    public function test_documents_routes_are_permission_protected(): void
    {
        $reception = $this->userWithRole('RECEPTION');

        $this->actingAs($reception)->get('/administration/generated-documents')->assertForbidden();
        $this->actingAs($reception)->get('/administration/generated-documents/create')->assertForbidden();
        $this->actingAs($this->administration)->get('/administration/generated-documents')->assertOk();
        $this->actingAs($this->administration)->get('/administration/generated-documents/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Documents/Create'));
    }

    public function test_preview_reports_missing_variables_and_store_requires_them_filled_then_freezes_the_snapshot(): void
    {
        $employee = $this->employee();
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Je soussigné {{nom}} {{prenom}}, matricule {{matricule}}. Motif : {{motif}}.</p>');

        $preview = $this->actingAs($this->administration)->postJson('/administration/generated-documents/preview', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertOk();
        $this->assertSame(['motif'], $preview->json('missing_variables'));
        $this->assertStringContainsString('Rabe', $preview->json('rendered_html'));

        // Blocked: {{motif}} is a manual-only variable (absent from the
        // known catalogue, exactly like {{salaire}} would be) and was never
        // filled in.
        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertSessionHasErrors('manual_variables');
        $this->assertDatabaseCount('generated_documents', 0);

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'manual_variables' => ['motif' => 'Voyage professionnel'],
        ])->assertSessionHasNoErrors();

        $document = GeneratedDocument::query()->sole();
        $this->assertStringContainsString('Voyage professionnel', $document->rendered_html_snapshot);
        $this->assertStringContainsString('Rabe', $document->rendered_html_snapshot);

        // The employee's identity changes afterward — the already-generated
        // document must never reflect it (same guarantee as
        // EmploymentContract.template_variables_snapshot, ADR-069).
        $employee->update(['last_name' => 'Nom modifié après génération']);
        $this->actingAs($this->administration)->get("/administration/generated-documents/{$document->uuid}/print")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.rendered_html', fn (string $html) => str_contains($html, 'Rabe')
                    && ! str_contains($html, 'Nom modifié')));
    }

    public function test_employee_and_contract_context_requires_a_contract_belonging_to_the_employee(): void
    {
        $employee = $this->employee();
        $otherEmployee = $this->employee();
        $contractType = HrReferenceValue::query()->where('type', HrReferenceType::ContractType->value)->where('label', 'CDI')->firstOrFail();
        $contract = EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => $contractType->id,
            'starts_on' => '2026-01-01',
        ]);
        $foreignContract = EmploymentContract::query()->create([
            'employee_id' => $otherEmployee->id,
            'contract_type_id' => $contractType->id,
            'starts_on' => '2026-01-01',
        ]);
        $template = $this->template(DocumentDataContext::EmployeeAndContract, '<p>{{nom}} — {{type_contrat}} du {{date_debut}}.</p>');

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertSessionHasErrors('employment_contract_uuid');

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'employment_contract_uuid' => $foreignContract->uuid,
        ])->assertSessionHasErrors('employment_contract_uuid');
        $this->assertDatabaseCount('generated_documents', 0);

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'employment_contract_uuid' => $contract->uuid,
        ])->assertSessionHasNoErrors();
        $this->assertSame($contract->id, GeneratedDocument::query()->sole()->employment_contract_id);
    }

    public function test_an_inactive_or_archived_template_is_not_selectable(): void
    {
        $employee = $this->employee();
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>{{nom}}</p>');
        $template->update(['active' => false]);

        $this->actingAs($this->administration)->postJson('/administration/generated-documents/preview', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertUnprocessable();

        $template->update(['active' => true]);
        $template->delete_reason = 'Retiré du service';
        $template->delete();

        $this->actingAs($this->administration)->postJson('/administration/generated-documents/preview', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertUnprocessable();
    }

    private function template(DocumentDataContext $context, string $html): DocumentTemplate
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', $html, $matches);

        return DocumentTemplate::query()->create([
            'lineage_id' => (string) Str::uuid(),
            'document_type' => 'ATTESTATION',
            'data_context' => $context,
            'name' => 'Attestation de travail',
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => $html,
            'variables_used' => collect($matches[1])->unique()->sort()->values()->all(),
            'active' => true,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'DOC-EMP-'.str()->random(8),
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'active' => true,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }
}
