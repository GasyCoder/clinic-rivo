<?php

namespace Tests\Feature\Http;

use App\Enums\PatientType;
use App\Models\AddressEntry;
use App\Models\AuditLog;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\PatientMutualCoverageAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientEditMutualCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_exposes_the_active_coverage_safe_documents_and_controlled_addresses(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([
            'patients.view',
            'patients.update',
            'patient_coverages.view',
            'patient_coverage_documents.view',
            'patient_coverage_documents.create',
            'address_entries.view',
            'address_entries.create',
        ]);
        $address = AddressEntry::query()->create(['label' => 'Mahajanga', 'active' => true]);
        AddressEntry::query()->create(['label' => 'Adresse inactive', 'active' => false]);
        [$patient, $coverage] = $this->mutualPatient($user, $address);
        $attachment = $this->attachment($coverage, $user);

        $this->actingAs($user)
            ->get("/patients/{$patient->uuid}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Patients/Edit')
                ->where('patient.uuid', $patient->uuid)
                ->where('patient.address_entry_uuid', $address->uuid)
                ->where('patient.active_mutual_coverage.uuid', $coverage->uuid)
                ->where('patient.active_mutual_coverage.organization.name', 'Mutuelle Santé 1')
                ->where('patient.active_mutual_coverage.employer_name', 'Clinique partenaire')
                ->where('patient.active_mutual_coverage.beneficiary_type', 'PRINCIPAL')
                ->where('patient.active_mutual_coverage.membership_number', 'MUT-123')
                ->where('patient.active_mutual_coverage.attachments_count', 1)
                ->where('patient.active_mutual_coverage.attachments.0.uuid', $attachment->uuid)
                ->where('patient.active_mutual_coverage.attachments.0.is_image', true)
                ->where(
                    'patient.active_mutual_coverage.attachments.0.url',
                    "/reception/mutual-coverages/{$coverage->uuid}/attachments/{$attachment->uuid}",
                )
                ->missing('patient.active_mutual_coverage.attachments.0.id')
                ->missing('patient.active_mutual_coverage.attachments.0.path')
                ->has('addressEntries', 1)
                ->where('addressEntries.0.uuid', $address->uuid)
                ->where('capabilities.can_view_coverage', true)
                ->where('capabilities.can_view_coverage_documents', true)
                ->where('capabilities.can_add_coverage_documents', true)
                ->where('capabilities.can_create_address_entry', true));
    }

    public function test_edit_does_not_expose_private_coverage_data_without_its_permissions(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        [$patient] = $this->mutualPatient($user);

        $this->actingAs($user)
            ->get("/patients/{$patient->uuid}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('patient.active_mutual_coverage', null)
                ->has('addressEntries', 0)
                ->where('capabilities.can_view_coverage', false)
                ->where('capabilities.can_view_coverage_documents', false)
                ->where('capabilities.can_add_coverage_documents', false));
    }

    public function test_adding_documents_requires_all_three_permissions(): void
    {
        Storage::fake('local');
        $owner = $this->userWithPermissions([], 'OWNER');
        [$patient, $coverage] = $this->mutualPatient($owner);
        $permissionSets = [
            ['patients.update', 'patient_coverages.view'],
            ['patients.update', 'patient_coverage_documents.create'],
            ['patient_coverages.view', 'patient_coverage_documents.create'],
        ];

        foreach ($permissionSets as $index => $permissions) {
            $user = $this->userWithPermissions($permissions, "LIMITED_{$index}");

            $this->actingAs($user)->post($this->storeUrl($patient, $coverage), [
                'files' => [UploadedFile::fake()->image("piece-{$index}.png")],
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('patient_mutual_coverage_attachments', 0);
    }

    public function test_authorized_user_can_add_private_documents_and_the_creation_is_audited(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([
            'patients.update',
            'patient_coverages.view',
            'patient_coverage_documents.create',
        ]);
        [$patient, $coverage] = $this->mutualPatient($user);

        $this->actingAs($user)
            ->from("/patients/{$patient->uuid}/edit")
            ->post($this->storeUrl($patient, $coverage), [
                'files' => [
                    UploadedFile::fake()->image('carte.png'),
                    UploadedFile::fake()->create('attestation.pdf', 200, 'application/pdf'),
                ],
            ])
            ->assertRedirect("/patients/{$patient->uuid}/edit")
            ->assertSessionHas('status', '2 justificatifs de mutuelle ajoutés.');

        $attachments = $coverage->attachments()->orderBy('id')->get();
        $this->assertCount(2, $attachments);
        $this->assertSame(['carte.png', 'attestation.pdf'], $attachments->pluck('original_name')->all());
        $attachments->each(function (PatientMutualCoverageAttachment $attachment) use ($user): void {
            Storage::disk('local')->assertExists($attachment->getRawOriginal('path'));
            $this->assertSame($user->id, $attachment->getRawOriginal('uploaded_by'));
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'create',
                'module' => 'reception',
                'entity_type' => PatientMutualCoverageAttachment::class,
                'entity_id' => $attachment->getKey(),
                'user_id' => $user->id,
            ]);
        });
    }

    public function test_five_document_limit_counts_existing_documents_atomically(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([
            'patients.update',
            'patient_coverages.view',
            'patient_coverage_documents.create',
        ]);
        [$patient, $coverage] = $this->mutualPatient($user);

        foreach (range(1, 4) as $index) {
            $this->attachment($coverage, $user, "piece-{$index}.png");
        }

        $this->actingAs($user)->post($this->storeUrl($patient, $coverage), [
            'files' => [
                UploadedFile::fake()->image('nouvelle-1.png'),
                UploadedFile::fake()->image('nouvelle-2.png'),
            ],
        ])->assertSessionHasErrors('files');

        $this->assertSame(4, $coverage->attachments()->count());
        Storage::disk('local')->assertMissing("patient-mutual-coverages/{$coverage->uuid}/".now()->format('Y/m').'/nouvelle-1.png');
    }

    public function test_document_upload_uses_scoped_uuid_bindings(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([
            'patients.update',
            'patient_coverages.view',
            'patient_coverage_documents.create',
        ]);
        [$patient, $coverage] = $this->mutualPatient($user);
        [$otherPatient, $otherCoverage] = $this->mutualPatient($user, suffix: '2');

        $payload = ['files' => [UploadedFile::fake()->image('carte.png')]];

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/mutual-coverages/{$coverage->uuid}/attachments", $payload)
            ->assertNotFound();

        $this->actingAs($user)
            ->post($this->storeUrl($patient, $otherCoverage), $payload)
            ->assertNotFound();

        $this->actingAs($user)
            ->post("/patients/{$otherPatient->uuid}/mutual-coverages/{$otherCoverage->id}/attachments", $payload)
            ->assertNotFound();
    }

    public function test_edit_updates_only_controlled_addresses_and_keeps_coverage_history_read_only(): void
    {
        $user = $this->userWithPermissions([
            'patients.update',
            'address_entries.view',
        ]);
        [$patient, $coverage] = $this->mutualPatient($user);
        $address = AddressEntry::query()->create(['label' => 'Ambondromamy centre', 'active' => true]);

        $this->actingAs($user)->put("/patients/{$patient->uuid}", [
            ...$this->patientPayload(),
            'address_entry_uuid' => $address->uuid,
            // These keys are intentionally outside UpdatePatientRequest:
            // Reception must not rewrite a historical coverage in place.
            'mutual_employer_name' => 'Entreprise falsifiée',
            'mutual_membership_number' => 'MUT-FALSIFIE',
        ])->assertRedirect("/patients/{$patient->uuid}");

        $patient->refresh();
        $this->assertSame($address->id, $patient->address_entry_id);
        $this->assertSame('Ambondromamy centre', $patient->address);
        $this->assertSame('Clinique partenaire', $coverage->fresh()->employer_name);
        $this->assertSame('MUT-123', $coverage->fresh()->membership_number);
    }

    public function test_edit_can_move_a_patient_from_one_controlled_address_to_another(): void
    {
        $user = $this->userWithPermissions([
            'patients.update',
            'address_entries.view',
        ]);
        $originalAddress = AddressEntry::query()->create(['label' => 'Mahajanga', 'active' => true]);
        [$patient] = $this->mutualPatient($user, $originalAddress);
        $newAddress = AddressEntry::query()->create(['label' => 'Ambondromamy centre', 'active' => true]);

        $this->actingAs($user)->put("/patients/{$patient->uuid}", [
            ...$this->patientPayload(),
            'address_entry_uuid' => $newAddress->uuid,
        ])->assertRedirect("/patients/{$patient->uuid}");

        $patient->refresh();
        $this->assertSame($newAddress->id, $patient->address_entry_id);
        $this->assertSame('Ambondromamy centre', $patient->address);
    }

    public function test_new_controlled_address_requires_its_permission_and_is_audited(): void
    {
        $withoutPermission = $this->userWithPermissions(['patients.update'], 'NO_ADDRESS_CREATE');
        [$firstPatient] = $this->mutualPatient($withoutPermission);

        $this->actingAs($withoutPermission)->put("/patients/{$firstPatient->uuid}", [
            ...$this->patientPayload(),
            'new_address_label' => 'Nouvelle adresse',
        ])->assertForbidden();

        $user = $this->userWithPermissions([
            'patients.update',
            'address_entries.create',
        ], 'ADDRESS_CREATE');
        [$patient] = $this->mutualPatient($user, suffix: '2');

        $this->actingAs($user)->put("/patients/{$patient->uuid}", [
            ...$this->patientPayload(),
            'new_address_label' => '  Nouvelle   adresse  ',
        ])->assertRedirect("/patients/{$patient->uuid}");

        $address = AddressEntry::query()->where('normalized_label', 'nouvelle adresse')->sole();
        $this->assertSame($address->id, $patient->fresh()->address_entry_id);
        $this->assertSame('Nouvelle adresse', $patient->fresh()->address);
        $this->assertSame(1, AuditLog::query()
            ->where('entity_type', AddressEntry::class)
            ->where('entity_id', $address->id)
            ->where('action', 'create')
            ->count());
    }

    /** @return array{Patient, PatientMutualCoverage} */
    private function mutualPatient(
        User $creator,
        ?AddressEntry $address = null,
        string $suffix = '1',
    ): array {
        $patient = Patient::query()->create([
            'patient_number' => "M-26-000{$suffix}",
            'patient_type' => PatientType::Mutual,
            'first_name' => 'Fara',
            'last_name' => "Rakoto {$suffix}",
            'birth_date' => '1990-05-12',
            'sex' => 'F',
            'address_entry_id' => $address?->id,
            'address' => $address?->label,
        ]);
        $organization = MutualOrganization::query()->create([
            'name' => "Mutuelle Santé {$suffix}",
            'active' => true,
        ]);
        $coverage = PatientMutualCoverage::query()->create([
            'patient_id' => $patient->id,
            'mutual_organization_id' => $organization->id,
            'employer_name' => 'Clinique partenaire',
            'beneficiary_type' => 'PRINCIPAL',
            'membership_number' => 'MUT-123',
            'created_by' => $creator->id,
            'effective_from' => now(),
        ]);

        return [$patient, $coverage];
    }

    private function attachment(
        PatientMutualCoverage $coverage,
        User $uploader,
        string $name = 'carte-mutuelle.png',
    ): PatientMutualCoverageAttachment {
        $path = "patient-mutual-coverages/{$coverage->uuid}/{$name}";
        Storage::disk('local')->put($path, 'image-privee');

        return $coverage->attachments()->create([
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size' => 13,
            'uploaded_by' => $uploader->id,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, string $roleCode = 'RECEPTION'): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array<string, mixed> */
    private function patientPayload(): array
    {
        return [
            'first_name' => 'Fara',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
        ];
    }

    private function storeUrl(Patient $patient, PatientMutualCoverage $coverage): string
    {
        return "/patients/{$patient->uuid}/mutual-coverages/{$coverage->uuid}/attachments";
    }
}
