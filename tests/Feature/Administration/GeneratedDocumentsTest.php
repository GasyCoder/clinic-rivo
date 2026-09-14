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
use App\Services\Administration\DocumentFormFieldCatalog;
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

    public function test_preview_prefills_page_one_and_store_requires_required_fields_then_freezes_the_snapshot(): void
    {
        // No first_name: "Prénom(s)" is a required page-1 field with no
        // known value to auto-fill from — a real, unavoidable gap the RH
        // must fill in, exactly like {{salaire}} used to be under the old
        // variable system.
        $employee = $this->employee(['first_name' => null]);
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Attestation émise par la Clinique Saint Georges.</p>');

        $preview = $this->actingAs($this->administration)->postJson('/administration/generated-documents/preview', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertOk();
        $this->assertSame('Rabe', $preview->json('form_values.nom'));
        $this->assertSame(['Prénom(s)'], $preview->json('missing_required_fields'));
        $this->assertStringContainsString('Rabe', $preview->json('rendered_html'));
        $this->assertStringContainsString('Attestation émise par la Clinique Saint Georges.', $preview->json('rendered_html'));

        // Blocked: "Prénom(s)" is required and still has no value.
        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertSessionHasErrors('form_data');
        $this->assertDatabaseCount('generated_documents', 0);

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'form_data' => ['prenom' => 'Soa'],
        ])->assertSessionHasNoErrors();

        $document = GeneratedDocument::query()->sole();
        $this->assertSame('Soa', $document->form_data_snapshot['prenom']);
        $this->assertStringContainsString('Soa', $document->rendered_html_snapshot);
        $this->assertStringContainsString('Rabe', $document->rendered_html_snapshot);
        $this->assertStringContainsString('Attestation émise par la Clinique Saint Georges.', $document->rendered_html_snapshot);

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

    public function test_page_one_fields_are_prefilled_but_overridable_by_form_data(): void
    {
        $employee = $this->employee();
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Contenu du canevas.</p>');

        $preview = $this->actingAs($this->administration)->postJson('/administration/generated-documents/preview', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'form_data' => ['nom' => 'Nom saisi manuellement par le RH'],
        ])->assertOk();

        // The RH-submitted value wins over the auto-filled known value for
        // the same key.
        $this->assertSame('Nom saisi manuellement par le RH', $preview->json('form_values.nom'));
        $this->assertSame([], $preview->json('missing_required_fields'));
    }

    public function test_generated_document_content_is_the_canevas_verbatim_with_no_substitution(): void
    {
        $employee = $this->employee();
        // A literal "{{nom}}" typed by the Super Admin is now meaningless
        // plain text — there is no substitution mechanism left to act on it.
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Texte libre contenant littéralement {{nom}} et {{prenom}}.</p>');

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertSessionHasNoErrors();

        $document = GeneratedDocument::query()->sole();
        $this->assertStringContainsString('Texte libre contenant littéralement {{nom}} et {{prenom}}.', $document->rendered_html_snapshot);
    }

    public function test_document_form_field_catalog_returns_expected_fields_per_data_context(): void
    {
        $catalog = app(DocumentFormFieldCatalog::class);

        $employeeOnlyKeys = collect($catalog->fieldsForContext(DocumentDataContext::EmployeeOnly))->pluck('key')->all();
        $this->assertSame(['nom', 'prenom', 'matricule', 'poste', 'service', 'date_naissance', 'date_embauche', 'date'], $employeeOnlyKeys);

        $contractKeys = collect($catalog->fieldsForContext(DocumentDataContext::EmployeeAndContract))->pluck('key')->all();
        $this->assertSame([
            'nom', 'prenom', 'matricule', 'poste', 'service', 'date_naissance', 'date_embauche', 'date',
            'type_contrat', 'reference_contrat', 'date_signature', 'date_debut', 'fin_periode_essai', 'date_fin',
        ], $contractKeys);

        $leaveKeys = collect($catalog->fieldsForContext(DocumentDataContext::EmployeeAndLeave))->pluck('key')->all();
        $this->assertSame([
            'nom', 'prenom', 'matricule', 'poste', 'service', 'date_naissance', 'date_embauche', 'date',
            'type_conge', 'date_depart', 'date_retour', 'jours_demandes', 'motif_conge',
        ], $leaveKeys);
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
        $template = $this->template(DocumentDataContext::EmployeeAndContract, '<p>Contrat de travail — contenu du canevas.</p>');

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
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Contenu du canevas.</p>');
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
        return DocumentTemplate::query()->create([
            'lineage_id' => (string) Str::uuid(),
            'document_type' => 'ATTESTATION',
            'data_context' => $context,
            'name' => 'Attestation de travail',
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => $html,
            'active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function employee(array $overrides = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'DOC-EMP-'.str()->random(8),
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'active' => true,
            ...$overrides,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }
}
