<?php

namespace Tests\Feature\Api;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminDocumentTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    public function test_store_requires_the_permission_extracts_placeholders_and_is_visible_to_the_portal_actor(): void
    {
        $this->withHeaders($this->headers([]))
            ->postJson('/api/v1/super-admin/document-templates', $this->payload())
            ->assertForbidden();

        $response = $this->withHeaders($this->headers(['document_templates.create']))
            ->postJson('/api/v1/super-admin/document-templates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'ATTESTATION')
            ->assertJsonPath('data.data_context', 'EMPLOYEE_ONLY')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.creator', 'Direction centrale');

        $this->assertSame(['matricule', 'nom', 'prenom'], $response->json('data.variables_used'));
        $this->assertDatabaseHas('document_templates', [
            'name' => 'Attestation de travail',
            'external_created_by_name' => 'Direction centrale',
        ]);
    }

    public function test_updating_a_template_already_used_by_a_generated_document_creates_a_new_version_instead_of_mutating_in_place(): void
    {
        $template = $this->createTemplateViaApi();
        $employee = Employee::query()->create([
            'employee_number' => 'DOC-API-001', 'last_name' => 'Rabe', 'sex' => 'F', 'civility' => 'MRS', 'active' => true,
        ]);
        GeneratedDocument::query()->create([
            'document_template_id' => $template->id,
            'template_name_snapshot' => $template->name,
            'document_type_snapshot' => $template->document_type,
            'employee_id' => $employee->id,
            'resolved_variables_snapshot' => ['nom' => 'Rabe'],
            'rendered_html_snapshot' => '<p>Rabe</p>',
        ]);

        $newPayload = [...$this->payload(), 'name' => 'Attestation de travail v2', 'content_html' => '<p>{{nom}} {{prenom}} — {{service}}</p>'];
        $this->withHeaders($this->headers(['document_templates.update']))
            ->putJson("/api/v1/super-admin/document-templates/{$template->uuid}", $newPayload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Attestation de travail v2')
            ->assertJsonPath('data.variables_used', ['nom', 'prenom', 'service']);

        $this->assertTrue($template->fresh()->trashed());
        $this->assertNotNull($template->fresh()->delete_reason);
        $this->assertDatabaseCount('document_templates', 2);
        // The generated document keeps pointing at the exact original
        // version — its own row is untouched by the new version.
        $this->assertSame($template->id, GeneratedDocument::query()->sole()->document_template_id);
    }

    public function test_updating_a_never_used_template_edits_it_in_place(): void
    {
        $template = $this->createTemplateViaApi();

        $this->withHeaders($this->headers(['document_templates.update']))
            ->putJson("/api/v1/super-admin/document-templates/{$template->uuid}", [
                ...$this->payload(), 'name' => 'Attestation renommée',
            ])->assertOk()
            ->assertJsonPath('data.uuid', $template->uuid);

        $this->assertDatabaseCount('document_templates', 1);
        $this->assertSame('Attestation renommée', $template->fresh()->name);
        $this->assertFalse($template->fresh()->trashed());
    }

    public function test_archive_restore_and_duplicate_require_their_own_permissions(): void
    {
        $template = $this->createTemplateViaApi();

        $this->withHeaders($this->headers(['document_templates.view']))
            ->deleteJson("/api/v1/super-admin/document-templates/{$template->uuid}", ['reason' => 'Doublon'])
            ->assertForbidden();

        $this->withHeaders($this->headers(['document_templates.archive']))
            ->deleteJson("/api/v1/super-admin/document-templates/{$template->uuid}", ['reason' => 'Doublon'])
            ->assertOk();
        $this->assertTrue($template->fresh()->trashed());

        $this->withHeaders($this->headers(['document_templates.restore']))
            ->postJson("/api/v1/super-admin/document-templates/{$template->uuid}/restore")
            ->assertOk();
        $this->assertFalse($template->fresh()->trashed());

        $copy = $this->withHeaders($this->headers(['document_templates.duplicate']))
            ->postJson("/api/v1/super-admin/document-templates/{$template->uuid}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.active', false);

        $this->assertSame($template->name.' (copie)', $copy->json('data.name'));
        $this->assertDatabaseCount('document_templates', 2);
    }

    public function test_history_lists_every_version_of_the_lineage_and_revert_creates_a_new_version_from_an_old_one(): void
    {
        $v1 = $this->createTemplateViaApi();
        $employee = Employee::query()->create([
            'employee_number' => 'DOC-API-002', 'last_name' => 'Rakoto', 'sex' => 'M', 'civility' => 'MR', 'active' => true,
        ]);
        $document = GeneratedDocument::query()->create([
            'document_template_id' => $v1->id,
            'template_name_snapshot' => $v1->name,
            'document_type_snapshot' => $v1->document_type,
            'employee_id' => $employee->id,
            'resolved_variables_snapshot' => ['nom' => 'Rakoto'],
            'rendered_html_snapshot' => '<p>Rakoto</p>',
        ]);

        // v1 is in use: editing it versions instead of mutating in place.
        $this->withHeaders($this->headers(['document_templates.update']))
            ->putJson("/api/v1/super-admin/document-templates/{$v1->uuid}", [
                ...$this->payload(), 'name' => 'Attestation de travail v2',
            ])->assertOk();
        $v2 = DocumentTemplate::query()->where('name', 'Attestation de travail v2')->firstOrFail();

        $this->withHeaders($this->headers([]))
            ->getJson("/api/v1/super-admin/document-templates/{$v1->uuid}/history")
            ->assertForbidden();

        $history = $this->withHeaders($this->headers(['document_templates.view']))
            ->getJson("/api/v1/super-admin/document-templates/{$v2->uuid}/history")
            ->assertOk();
        $this->assertCount(2, $history->json('data'));
        $this->assertSame(
            [$v1->uuid => true, $v2->uuid => false],
            collect($history->json('data'))->pluck('archived', 'uuid')->all(),
        );

        // Reverting to the still-active v2 (not archived) is refused.
        $this->withHeaders($this->headers(['document_templates.update']))
            ->postJson("/api/v1/super-admin/document-templates/{$v2->uuid}/revert", ['reason' => 'Test'])
            ->assertStatus(422);

        $this->withHeaders($this->headers(['document_templates.view']))
            ->postJson("/api/v1/super-admin/document-templates/{$v1->uuid}/revert", ['reason' => 'Erreur dans la v2'])
            ->assertForbidden();

        $revert = $this->withHeaders($this->headers(['document_templates.update']))
            ->postJson("/api/v1/super-admin/document-templates/{$v1->uuid}/revert", ['reason' => 'Erreur dans la v2'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Attestation de travail');
        $v3Uuid = $revert->json('data.uuid');

        $this->assertTrue($v2->fresh()->trashed());
        $this->assertTrue($v1->fresh()->trashed(), 'v1 stays archived — reverting never resurrects the old row.');
        $this->assertDatabaseCount('document_templates', 3);
        // The lineage — not the specific row — is what's tracked, so the
        // generated document still resolves the exact original version.
        $this->assertSame($v1->id, $document->fresh()->document_template_id);

        $finalHistory = $this->withHeaders($this->headers(['document_templates.view']))
            ->getJson("/api/v1/super-admin/document-templates/{$v3Uuid}/history")
            ->assertOk();
        $this->assertCount(3, $finalHistory->json('data'));
        $this->assertSame(
            [$v1->uuid => true, $v2->uuid => true, $v3Uuid => false],
            collect($finalHistory->json('data'))->pluck('archived', 'uuid')->all(),
        );
    }

    private function createTemplateViaApi(): DocumentTemplate
    {
        $this->withHeaders($this->headers(['document_templates.create']))
            ->postJson('/api/v1/super-admin/document-templates', $this->payload())
            ->assertCreated();

        return DocumentTemplate::query()->sole();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'document_type' => 'attestation',
            'data_context' => 'EMPLOYEE_ONLY',
            'name' => 'Attestation de travail',
            'description' => null,
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>Je soussigné, {{nom}} {{prenom}}, matricule {{matricule}}.</p>',
            'active' => true,
        ];
    }

    /** @param array<int, string> $permissions */
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
