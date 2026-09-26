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
use App\Support\Documents\DocumentFamily;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-199 — les documents du personnel en dossiers (Contrats, Congés,
 * Attestations…) : voir, modifier (nouvelle version), archiver, restaurer,
 * générer ; « Imprimer » un contrat prend son document tout seul.
 */
class DocumentFoldersTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_a_type_is_a_folder_whatever_its_accents_or_case(): void
    {
        $this->assertSame('CONGE', DocumentFamily::key('Congé'));
        $this->assertSame('CONGE', DocumentFamily::key(' conge '));
        $this->assertSame('AUTRE', DocumentFamily::key(''));
        $this->assertSame('Congés', DocumentFamily::label('CONGE'));
        $this->assertSame(DocumentDataContext::EmployeeAndLeave, DocumentFamily::context('CONGE'));
        $this->assertSame('Note de service', DocumentFamily::label('NOTE DE SERVICE'));
        $this->assertSame(['CONGE', 'Congé'], DocumentFamily::typesIn('CONGE', ['CONGE', 'Congé', 'CONTRAT']));
    }

    public function test_the_root_lists_every_folder_and_a_folder_shows_its_templates_and_documents(): void
    {
        $employee = $this->employee();
        $attestation = $this->template('ATTESTATION', DocumentDataContext::EmployeeOnly);
        $this->template('NOTE DE SERVICE', DocumentDataContext::EmployeeOnly);
        $this->generate($attestation, $employee);

        $this->actingAs($this->hr)->get('/administration/generated-documents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Documents/Index')
                ->has('folders', 8)
                ->where('folders.0.key', 'CONTRAT')
                ->where('folders.2.key', 'ATTESTATION')
                ->where('folders.2.templates', 1)
                ->where('folders.2.documents', 1)
                ->where('folders.7.key', 'NOTE DE SERVICE')
                ->where('folders.7.label', 'Note de service')
                ->where('folder', null)
                ->where('documents', null));

        $this->actingAs($this->hr)->get('/administration/generated-documents?dossier=attestation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder.key', 'ATTESTATION')
                ->where('folder.label', 'Attestations')
                ->has('templates', 1)
                ->where('templates.0.uuid', $attestation->uuid)
                ->has('documents.data', 1)
                ->where('documents.data.0.folder', 'ATTESTATION')
                ->where('documents.data.0.archived', false));

        $this->actingAs($this->hr)->get('/administration/generated-documents?dossier=CONTRAT')
            ->assertInertia(fn (Assert $page) => $page->has('templates', 0)->has('documents.data', 0));
    }

    public function test_modifying_a_document_makes_a_new_version_and_archives_the_old_one(): void
    {
        $employee = $this->employee();
        $template = $this->template('ATTESTATION', DocumentDataContext::EmployeeOnly);
        $first = $this->generate($template, $employee, ['poste' => 'Infirmière']);

        $this->actingAs($this->hr)->get("/administration/generated-documents/create?from={$first->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.document_template_uuid', $template->uuid)
                ->where('prefill.employee_uuid', $employee->uuid)
                ->where('prefill.form_data.poste', 'Infirmière')
                ->where('replaces.uuid', $first->uuid));

        $this->actingAs($this->hr)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'form_data' => ['poste' => 'Infirmière chef'],
            'replaces_uuid' => $first->uuid,
        ])->assertSessionHasNoErrors();

        $second = GeneratedDocument::query()->sole();
        $this->assertSame($first->id, $second->replaces_document_id);
        $this->assertSame('Infirmière chef', $second->form_data_snapshot['poste']);
        $first = GeneratedDocument::withTrashed()->findOrFail($first->id);
        $this->assertTrue($first->trashed());
        $this->assertSame('Remplacé par une nouvelle version.', $first->delete_reason);
        // Figé : l'ancienne version garde ce qu'elle disait.
        $this->assertSame('Infirmière', $first->form_data_snapshot['poste']);

        // L'ancienne version reste lisible, et ne revient pas tant que la nouvelle est en vigueur.
        $this->actingAs($this->hr)->get("/administration/generated-documents/{$first->uuid}/print")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('document.archived', true)->where('document.replaced_by.uuid', $second->uuid));
        $this->actingAs($this->hr)->post("/administration/generated-documents/{$first->uuid}/restore")
            ->assertSessionHasErrors('document');
        $this->assertTrue($first->fresh()->trashed());
    }

    public function test_a_new_version_must_concern_the_same_person(): void
    {
        $template = $this->template('ATTESTATION', DocumentDataContext::EmployeeOnly);
        $first = $this->generate($template, $this->employee());

        $this->actingAs($this->hr)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $this->employee()->uuid,
            'form_data' => ['poste' => 'Aide-soignante'],
            'replaces_uuid' => $first->uuid,
        ])->assertSessionHasErrors();

        $this->assertFalse($first->fresh()->trashed());
        $this->assertSame(1, GeneratedDocument::withTrashed()->count());
    }

    public function test_deleting_a_document_archives_it_with_a_reason_and_it_can_be_restored(): void
    {
        $document = $this->generate($this->template('ATTESTATION', DocumentDataContext::EmployeeOnly), $this->employee());

        $this->actingAs($this->hr)->delete("/administration/generated-documents/{$document->uuid}", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($document->fresh()->trashed());

        $this->actingAs($this->hr)->delete("/administration/generated-documents/{$document->uuid}", ['reason' => 'Erreur de saisie'])
            ->assertRedirect(route('administration.generated-documents.index', ['dossier' => 'ATTESTATION']));
        $archived = GeneratedDocument::withTrashed()->findOrFail($document->id);
        $this->assertTrue($archived->trashed());
        $this->assertSame('Erreur de saisie', $archived->delete_reason);
        $this->assertSame($this->hr->id, $archived->deleted_by);

        $this->actingAs($this->hr)->get('/administration/generated-documents?dossier=ATTESTATION')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 0)->where('folders.2.archived', 1));
        $this->actingAs($this->hr)->get('/administration/generated-documents?dossier=ATTESTATION&statut=archives')
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.archived', true)
                ->where('documents.data.0.archive_reason', 'Erreur de saisie'));

        $this->actingAs($this->hr)->post("/administration/generated-documents/{$document->uuid}/restore")
            ->assertSessionHasNoErrors();
        $this->assertFalse($document->fresh()->trashed());
        // Jamais effacé.
        $this->assertSame(1, GeneratedDocument::withTrashed()->count());
    }

    public function test_archiving_and_restoring_are_their_own_rights(): void
    {
        $document = $this->generate($this->template('ATTESTATION', DocumentDataContext::EmployeeOnly), $this->employee());
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        $this->actingAs($reception)->delete("/administration/generated-documents/{$document->uuid}", ['reason' => 'Essai'])
            ->assertForbidden();
        $this->actingAs($reception)->post("/administration/generated-documents/{$document->uuid}/restore")->assertForbidden();
        $this->assertFalse($document->fresh()->trashed());
    }

    public function test_printing_a_contract_opens_the_document_already_produced_for_it(): void
    {
        $employee = $this->employee();
        $contract = $this->contract($employee);
        $template = $this->template('CONTRAT', DocumentDataContext::EmployeeAndContract);
        $this->template('CONTRAT', DocumentDataContext::EmployeeAndContract, 'Contrat à durée déterminée');

        // Deux canevas de contrat et aucun document : l'écran de choix.
        $this->actingAs($this->hr)->get("/administration/contracts/{$contract->uuid}/print")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Contracts/Print')->has('templates', 2));

        $document = $this->generate($template, $employee, [], $contract);

        $this->actingAs($this->hr)->get("/administration/contracts/{$contract->uuid}/print")
            ->assertRedirect(route('administration.generated-documents.print', $document->uuid));
        $this->actingAs($this->hr)->get("/administration/contracts/{$contract->uuid}/print?choisir=1")->assertOk();
    }

    private function template(string $type, DocumentDataContext $context, string $name = 'Modèle'): DocumentTemplate
    {
        return DocumentTemplate::query()->create([
            'lineage_id' => (string) Str::uuid(),
            'document_type' => $type,
            'data_context' => $context,
            'name' => $name,
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>Contenu du canevas.</p>',
            'active' => true,
        ]);
    }

    /** @param array<string, string> $formData */
    private function generate(DocumentTemplate $template, Employee $employee, array $formData = [], ?EmploymentContract $contract = null): GeneratedDocument
    {
        $this->actingAs($this->hr)->post('/administration/generated-documents', [
            'document_template_uuid' => $template->uuid,
            'employee_uuid' => $employee->uuid,
            'employment_contract_uuid' => $contract?->uuid,
            'form_data' => $formData,
        ])->assertSessionHasNoErrors();

        return GeneratedDocument::query()->latest('id')->firstOrFail();
    }

    private function contract(Employee $employee): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => HrReferenceValue::query()->where('type', HrReferenceType::ContractType->value)->where('label', 'CDI')->value('id'),
            'starts_on' => '2026-01-01',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'FOLD-'.str()->random(8),
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'active' => true,
        ]);
    }
}
