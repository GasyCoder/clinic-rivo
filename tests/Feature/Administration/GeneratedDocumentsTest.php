<?php

namespace Tests\Feature\Administration;

use App\Enums\DocumentDataContext;
use App\Enums\HrReferenceType;
use App\Models\AppSetting;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\HrReferenceValue;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\DocumentFormFieldCatalog;
use App\Services\Settings\AppSettings;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * ADR-184 — le directeur général signe au bas du document, sur demande. La
     * signature y est copiée : la remplacer ensuite ne change aucun document
     * déjà produit.
     */
    public function test_the_director_signature_is_frozen_into_the_document_when_requested(): void
    {
        Storage::fake(AppSettings::DISK);
        $first = UploadedFile::fake()->image('signature.png', 200, 80)->store('branding', AppSettings::DISK);
        AppSetting::query()->create(['director_name' => 'Dr Rakoto Jean', 'director_title' => 'Directeur général', 'signature_path' => $first]);
        app(AppSettings::class)->forget();

        $employee = $this->employee();
        $template = $this->template(DocumentDataContext::EmployeeOnly, '<p>Attestation.</p>');

        $this->actingAs($this->administration)
            ->get('/administration/generated-documents/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('director.name', 'Dr Rakoto Jean')
                ->where('director.has_signature', true));

        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'with_director_signature' => true,
        ])->assertSessionHasNoErrors();

        $snapshot = GeneratedDocument::query()->sole()->rendered_html_snapshot;
        $this->assertStringContainsString('data-director-signature', $snapshot);
        $this->assertStringContainsString('Dr Rakoto Jean', $snapshot);
        $this->assertStringContainsString('src="data:image/png;base64,', $snapshot);

        // Une nouvelle signature ne réécrit pas le document déjà remis.
        $second = UploadedFile::fake()->image('other.png', 120, 40)->store('branding', AppSettings::DISK);
        AppSetting::query()->update(['signature_path' => $second]);
        $this->assertSame($snapshot, GeneratedDocument::query()->sole()->rendered_html_snapshot);

        // Sans la demande, pas de bloc.
        $this->actingAs($this->administration)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('data-director-signature', GeneratedDocument::query()->latest('id')->first()->rendered_html_snapshot);
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

    public function test_printing_a_contract_offers_the_contract_canevas_and_opens_generation_prefilled(): void
    {
        $employee = $this->employee();
        $otherEmployee = $this->employee();
        $contractType = HrReferenceValue::query()->where('type', HrReferenceType::ContractType->value)->where('label', 'CDI')->firstOrFail();
        $contract = EmploymentContract::query()->create(['employee_id' => $employee->id, 'contract_type_id' => $contractType->id, 'starts_on' => '2026-01-01']);
        $foreignContract = EmploymentContract::query()->create(['employee_id' => $otherEmployee->id, 'contract_type_id' => $contractType->id, 'starts_on' => '2026-01-01']);
        $contractTemplate = $this->template(DocumentDataContext::EmployeeAndContract, '<p>Contrat.</p>');
        $this->template(DocumentDataContext::EmployeeOnly, '<p>Attestation.</p>');

        // ADR-199 — un seul canevas de contrat : « Imprimer » ouvre la génération tout seul.
        $this->actingAs($this->administration)->get("/administration/contracts/{$contract->uuid}/print")
            ->assertRedirect(route('administration.generated-documents.create', [
                'template' => $contractTemplate->uuid, 'employee' => $employee->uuid, 'contract' => $contract->uuid, 'dossier' => 'CONTRAT',
            ]));

        // `?choisir=1` garde l'écran de choix.
        $this->actingAs($this->administration)->get("/administration/contracts/{$contract->uuid}/print?choisir=1")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Contracts/Print')
                ->has('templates', 1)
                ->where('templates.0.uuid', $contractTemplate->uuid));

        $query = http_build_query(['template' => $contractTemplate->uuid, 'employee' => $employee->uuid, 'contract' => $contract->uuid]);
        $this->actingAs($this->administration)->get("/administration/generated-documents/create?{$query}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.document_template_uuid', $contractTemplate->uuid)
                ->where('prefill.employee_uuid', $employee->uuid)
                ->where('prefill.employment_contract_uuid', $contract->uuid));

        // A contract of another employee is never pre-selected.
        $query = http_build_query(['template' => $contractTemplate->uuid, 'employee' => $employee->uuid, 'contract' => $foreignContract->uuid]);
        $this->actingAs($this->administration)->get("/administration/generated-documents/create?{$query}")
            ->assertInertia(fn (Assert $page) => $page->where('prefill.employment_contract_uuid', ''));
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
