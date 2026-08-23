<?php

namespace Tests\Feature\Http;

use App\Enums\PatientType;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\PatientMutualCoverageAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientMutualCoverageAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_attachment_requires_patient_and_document_view_permissions(): void
    {
        Storage::fake('local');
        [$coverage, $attachment] = $this->coverageWithAttachment();

        $patientViewer = $this->userWithPermissions(['patients.view']);
        $documentViewer = $this->userWithPermissions(['patient_coverage_documents.view'], 'NURSE');

        $this->actingAs($patientViewer)
            ->get($this->attachmentUrl($coverage, $attachment))
            ->assertForbidden();

        $this->actingAs($documentViewer)
            ->get($this->attachmentUrl($coverage, $attachment))
            ->assertForbidden();
    }

    public function test_authorized_user_receives_an_inline_private_response_and_the_view_is_audited(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions([
            'patients.view',
            'patient_coverage_documents.view',
        ]);
        [$coverage, $attachment] = $this->coverageWithAttachment(
            uploader: $viewer,
            contents: 'contenu-image-prive',
        );

        $response = $this->actingAs($viewer)
            ->get($this->attachmentUrl($coverage, $attachment));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('contenu-image-prive', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $viewer->id,
            'action' => 'patient_coverage_document.view',
            'module' => 'reception',
            'entity_type' => PatientMutualCoverageAttachment::class,
            'entity_id' => $attachment->id,
            'entity_uuid' => $attachment->uuid,
        ]);
    }

    public function test_attachment_route_uses_uuids_and_scopes_the_attachment_to_its_coverage(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions([
            'patients.view',
            'patient_coverage_documents.view',
        ]);
        [$coverage, $attachment] = $this->coverageWithAttachment(uploader: $viewer);
        [$otherCoverage] = $this->coverageWithAttachment(uploader: $viewer, suffix: '2');

        $this->actingAs($viewer)
            ->get("/reception/mutual-coverages/{$coverage->id}/attachments/{$attachment->uuid}")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->get("/reception/mutual-coverages/{$coverage->uuid}/attachments/{$attachment->id}")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->get($this->attachmentUrl($otherCoverage, $attachment))
            ->assertNotFound();
    }

    public function test_patient_page_exposes_only_safe_attachment_metadata(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions([
            'patients.view',
            'patient_coverages.view',
            'patient_coverage_documents.view',
        ]);
        [$coverage, $attachment] = $this->coverageWithAttachment(uploader: $viewer);

        $this->actingAs($viewer)
            ->get("/patients/{$coverage->patient->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Patients/Show')
                ->where('patient.active_mutual_coverage.attachments.0.uuid', $attachment->uuid)
                ->where('patient.active_mutual_coverage.attachments.0.original_name', 'carte-mutuelle.png')
                ->where('patient.active_mutual_coverage.attachments.0.mime_type', 'image/png')
                ->where('patient.active_mutual_coverage.attachments.0.is_image', true)
                ->missing('patient.active_mutual_coverage.attachments.0.id')
                ->missing('patient.active_mutual_coverage.attachments.0.path')
                ->missing('patient.active_mutual_coverage.attachments.0.patient_mutual_coverage_id')
                ->missing('patient.active_mutual_coverage.attachments.0.uploaded_by'));
    }

    public function test_an_unsupported_inline_type_or_missing_private_file_is_refused(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions([
            'patients.view',
            'patient_coverage_documents.view',
        ]);
        [$coverage, $attachment] = $this->coverageWithAttachment(uploader: $viewer);

        $attachment->update(['mime_type' => 'text/html']);

        $this->actingAs($viewer)
            ->get($this->attachmentUrl($coverage, $attachment))
            ->assertStatus(415);

        $attachment->update(['mime_type' => 'image/png']);
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($viewer)
            ->get($this->attachmentUrl($coverage, $attachment))
            ->assertNotFound();
    }

    /**
     * @return array{PatientMutualCoverage, PatientMutualCoverageAttachment}
     */
    private function coverageWithAttachment(
        ?User $uploader = null,
        string $contents = 'image-privee',
        string $suffix = '1',
    ): array {
        $uploader ??= User::factory()->create();
        $patient = Patient::query()->create([
            'patient_number' => "M-26-00{$suffix}",
            'patient_type' => PatientType::Mutual,
            'first_name' => "Fara {$suffix}",
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
        ]);
        $organization = MutualOrganization::query()->create([
            'name' => "Mutuelle {$suffix}",
            'active' => true,
        ]);
        $coverage = PatientMutualCoverage::query()->create([
            'patient_id' => $patient->id,
            'mutual_organization_id' => $organization->id,
            'employer_name' => "Entreprise {$suffix}",
            'beneficiary_type' => 'PRINCIPAL',
            'membership_number' => "MAT-{$suffix}",
            'created_by' => $uploader->id,
            'effective_from' => now(),
        ]);
        $path = "patient-mutual-coverages/{$coverage->uuid}/carte-mutuelle-{$suffix}.png";
        Storage::disk('local')->put($path, $contents);
        $attachment = $coverage->attachments()->create([
            'path' => $path,
            'original_name' => 'carte-mutuelle.png',
            'mime_type' => 'image/png',
            'size' => strlen($contents),
            'uploaded_by' => $uploader->id,
        ]);

        return [$coverage, $attachment];
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, string $roleCode = 'RECEPTION'): User
    {
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function attachmentUrl(
        PatientMutualCoverage $coverage,
        PatientMutualCoverageAttachment $attachment,
    ): string {
        return "/reception/mutual-coverages/{$coverage->uuid}/attachments/{$attachment->uuid}";
    }
}
