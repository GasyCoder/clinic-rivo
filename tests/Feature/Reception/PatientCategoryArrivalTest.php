<?php

namespace Tests\Feature\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeFinancialMode;
use App\Enums\PatientType;
use App\Enums\ReceptionRoutingMode;
use App\Models\AddressEntry;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\EpisodeMutualCoverage;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PatientCategoryArrivalTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_arrival_keeps_a_declared_age_without_inventing_a_birth_date(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'episodes.create', 'address_entries.create',
        ]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($actor)->post('/reception/patients', [
            ...$this->standardPatient(),
            'birth_date' => null,
            'age' => 41,
            'marital_status' => 'MARRIED',
            'children_count' => 3,
            'profession' => 'Enseignante',
            'new_address_label' => '  Quartier Anosibe, Mampikony  ',
        ]);

        $patient = Patient::query()->sole();
        $episode = Episode::query()->sole();

        $response->assertRedirect(route('reception.passages.services.show', $episode));
        $this->assertSame(PatientType::Standard, $patient->patient_type);
        $this->assertNull($patient->birth_date);
        $this->assertSame(41, $patient->declared_age);
        $this->assertNotNull($patient->declared_age_at);
        $this->assertSame('Quartier Anosibe, Mampikony', $patient->address);
        $this->assertNotNull($patient->address_entry_id);
        $this->assertSame('M-'.now()->format('y').'-0001', $patient->patient_number);
        $this->assertSame("{$patient->patient_number}-01", $episode->episode_number);
    }

    public function test_mutual_arrival_creates_the_coverage_and_at_most_five_private_files(): void
    {
        Storage::fake('local');
        $actor = $this->receptionist([
            'patients.create', 'episodes.create',
            'mutual_organizations.view', 'mutual_organizations.create',
            'patient_coverages.create', 'patient_coverage_documents.create',
        ]);

        $files = [
            UploadedFile::fake()->image('recto.jpg'),
            UploadedFile::fake()->create('contrat.pdf', 120, 'application/pdf'),
        ];

        $response = $this->actingAs($actor)->post('/reception/patients', [
            ...$this->standardPatient(),
            'patient_type' => PatientType::Mutual->value,
            'mutual_organization_name' => 'Mutuelle Santé CSG',
            'mutual_employer_name' => 'Entreprise Exemple',
            'mutual_beneficiary_type' => 'FAMILY_MEMBER',
            'mutual_membership_number' => 'MAT-2026-19',
            'mutual_attachments' => $files,
        ]);

        $patient = Patient::query()->sole();
        $coverage = EpisodeMutualCoverage::query()->with('attachments')->sole();
        $episode = Episode::query()->sole();

        $response->assertRedirect(route('reception.passages.services.show', $episode));
        $this->assertSame(PatientType::Standard, $patient->patient_type);
        $this->assertSame(EpisodeFinancialMode::Mutual, $episode->financial_mode);
        $this->assertSame($episode->id, $coverage->episode_id);
        $this->assertSame('FAMILY_MEMBER', $coverage->beneficiary_type->value);
        $this->assertCount(2, $coverage->attachments);
        $this->assertDatabaseCount('patient_mutual_coverages', 0);

        foreach ($coverage->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->getRawOriginal('path'));
        }
    }

    public function test_mutual_arrival_rejects_more_than_five_files_before_creating_a_patient(): void
    {
        Storage::fake('local');
        $actor = $this->receptionist([
            'patients.create', 'episodes.create',
            'mutual_organizations.view', 'mutual_organizations.create',
            'patient_coverages.create', 'patient_coverage_documents.create',
        ]);

        $response = $this->actingAs($actor)->post('/reception/patients', [
            ...$this->standardPatient(),
            'patient_type' => PatientType::Mutual->value,
            'mutual_organization_name' => 'Mutuelle Santé CSG',
            'mutual_employer_name' => 'Entreprise Exemple',
            'mutual_beneficiary_type' => 'PRINCIPAL',
            'mutual_membership_number' => 'MAT-2026-20',
            'mutual_attachments' => collect(range(1, 6))
                ->map(fn (int $index) => UploadedFile::fake()->image("piece-{$index}.jpg"))
                ->all(),
        ]);

        $response->assertSessionHasErrors('mutual_attachments');
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_mutual_coverages', 0);
    }

    public function test_staff_arrival_uses_the_hr_record_and_reuses_one_permanent_patient(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'episodes.create', 'employees.patient_lookup',
            'patient_staff_links.create',
        ]);
        config(['rivo.site.code' => 'A']);

        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0007',
            'civility' => 'MRS',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'sex' => 'F',
            'birth_date' => '1988-03-09',
            'profession' => 'Infirmière',
            'phone' => '0340000000',
            'active' => true,
        ]);

        $payload = [
            'patient_type' => PatientType::Staff->value,
            'employee_uuid' => $employee->uuid,
        ];

        $first = $this->actingAs($actor)->post('/reception/patients', $payload);
        $patient = Patient::query()->sole();
        $firstEpisode = Episode::query()->sole();
        $first->assertRedirect(route('reception.passages.services.show', $firstEpisode));

        $this->assertSame(PatientType::Standard, $patient->patient_type);
        $this->assertSame(EpisodeFinancialMode::Staff, $firstEpisode->financial_mode);
        $this->assertSame('Fara', $patient->first_name);
        $this->assertSame('Infirmière', $patient->profession);
        $this->assertDatabaseHas('patient_staff_links', [
            'patient_id' => $patient->id,
            'employee_id' => $employee->id,
            'ended_at' => null,
        ]);

        // A later arrival may come from the ordinary patient search instead
        // of the Personnel selector; the RH record remains authoritative in
        // both entry paths.
        $this->actingAs($actor)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
        ])->assertRedirect();

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_staff_links', 1);
        $this->assertDatabaseCount('episodes', 2);
        $this->assertSame(
            [EpisodeFinancialMode::Staff, EpisodeFinancialMode::Self],
            Episode::query()->orderBy('id')->get()->pluck('financial_mode')->all(),
        );
        $this->assertSame(
            ["{$patient->patient_number}-01", "{$patient->patient_number}-02"],
            Episode::query()->orderBy('id')->pluck('episode_number')->all(),
        );
    }

    public function test_a_linked_staff_patient_is_resynchronized_from_hr_at_each_arrival(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'episodes.create', 'employees.patient_lookup',
            'patient_staff_links.create',
        ]);
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0010',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'sex' => 'F',
            'birth_date' => '1988-03-09',
            'profession' => 'Infirmière',
            'phone' => '0340000000',
            'active' => true,
        ]);
        $payload = [
            'patient_type' => PatientType::Staff->value,
            'employee_uuid' => $employee->uuid,
        ];

        $this->actingAs($actor)->post('/reception/patients', $payload)->assertRedirect();
        $patient = Patient::query()->sole();

        $employee->update([
            'first_name' => 'Faramalala',
            'profession' => 'Infirmière major',
            'phone' => '0331111111',
            'email' => 'fara@clinique.mg',
            'address' => 'Lot RH 12',
        ]);

        $this->actingAs($actor)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
        ])->assertRedirect();

        $patient->refresh();
        $this->assertSame('Faramalala', $patient->first_name);
        $this->assertSame('Infirmière major', $patient->profession);
        $this->assertSame('0331111111', $patient->phone);
        $this->assertSame('fara@clinique.mg', $patient->email);
        $this->assertSame('Lot RH 12', $patient->address);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('patient_staff_links', 1);
        $this->assertDatabaseCount('episodes', 2);
    }

    public function test_a_linked_staff_patient_moves_to_the_employees_new_controlled_address_at_resync(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'episodes.create', 'employees.patient_lookup',
            'patient_staff_links.create',
        ]);
        $originalAddress = AddressEntry::query()->create(['label' => 'Lot RH 12', 'active' => true]);
        $newAddress = AddressEntry::query()->create(['label' => 'Lot RH 27', 'active' => true]);
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0011',
            'first_name' => 'Tovo',
            'last_name' => 'Andria',
            'sex' => 'M',
            'birth_date' => '1985-01-20',
            'profession' => 'Aide-soignant',
            'phone' => '0340000001',
            'address_entry_id' => $originalAddress->id,
            'active' => true,
        ]);

        $this->actingAs($actor)->post('/reception/patients', [
            'patient_type' => PatientType::Staff->value,
            'employee_uuid' => $employee->uuid,
        ])->assertRedirect();
        $patient = Patient::query()->sole();
        $this->assertSame($originalAddress->id, $patient->address_entry_id);

        $employee->update(['address_entry_id' => $newAddress->id]);

        $this->actingAs($actor)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
        ])->assertRedirect();

        $patient->refresh();
        $this->assertSame($newAddress->id, $patient->address_entry_id);
        $this->assertSame('Lot RH 27', $patient->address);
    }

    public function test_staff_services_keep_the_clinical_route_but_wait_for_the_hr_finance_coverage(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'patients.view',
            'episodes.create', 'episodes.view', 'episodes.update',
            'employees.patient_lookup', 'patient_staff_links.create',
            'billing.create',
        ]);
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0012',
            'first_name' => 'Lala',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'birth_date' => '1991-11-02',
            'active' => true,
        ]);
        $service = CatalogItem::query()->create([
            'code' => 'INJECTION-STAFF-TEST',
            'name' => 'Injection de test',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::CareOnly,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        CatalogTariff::query()->create([
            'catalog_item_id' => $service->id,
            'amount' => '5000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor)->post('/reception/patients', [
            'patient_type' => PatientType::Staff->value,
            'employee_uuid' => $employee->uuid,
        ]);
        $episode = Episode::query()->sole();

        $response = $this->actingAs($actor)->post(
            route('reception.passages.services.store', $episode),
            [
                'catalog_lines' => [[
                    'catalog_item_uuid' => $service->uuid,
                    'quantity' => 1,
                ]],
            ],
        );

        $response->assertRedirect(route('patients.show', $episode->patient));
        $response->assertSessionHas('status', fn (string $status) => str_contains(
            $status,
            'couverture Personnel doit être calculée par RH / Finance',
        ));
        $this->assertDatabaseCount('episode_service_requests', 1);
        $this->assertDatabaseCount('episode_orientations', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);

        $this->actingAs($actor)->post(route('invoices.store', $episode->patient), [
            'episode_uuid' => $episode->uuid,
            'catalog_lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 1,
            ]],
        ])->assertSessionHasErrors('financial_mode');

        $this->assertDatabaseCount('billable_items', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @param array<int, string> $permissions */
    private function receptionist(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array<string, mixed> */
    private function standardPatient(): array
    {
        return [
            'patient_type' => PatientType::Standard->value,
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1985-07-17',
            'sex' => 'F',
        ];
    }
}
