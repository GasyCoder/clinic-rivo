<?php

namespace Tests\Feature\Http;

use App\Enums\VisitorCategory;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitorVisit;
use App\Models\VisitorVisitAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisitorReceptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function visitorData(array $overrides = []): array
    {
        return [
            'full_name' => 'Eric Randria',
            'phone' => '0341234567',
            'category' => VisitorCategory::PatientOrFamilyVisit->value,
            'reason' => 'Visite à un membre de la famille hospitalisé.',
            ...$overrides,
        ];
    }

    public function test_index_requires_the_visitors_view_permission(): void
    {
        $user = $this->userWithPermissions(['reception.view']);

        $this->actingAs($user)->get('/reception/visitors')->assertForbidden();
    }

    public function test_entry_and_exit_require_their_own_permissions(): void
    {
        $user = $this->userWithPermissions(['visitors.view']);
        $visitor = VisitorVisit::create([
            ...$this->visitorData(),
            'checked_in_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'full_name' => 'Nouvelle personne',
        ]))->assertForbidden();

        $this->actingAs($user)->post("/reception/visitors/{$visitor->uuid}/close")
            ->assertForbidden();

        $this->assertSame(1, VisitorVisit::count());
        $this->assertNull($visitor->fresh()->checked_out_at);
    }

    public function test_index_lists_present_and_recently_departed_visitors(): void
    {
        $user = $this->userWithPermissions(['visitors.view']);
        VisitorVisit::create([
            ...$this->visitorData(),
            'checked_in_at' => now()->subHour(),
            'created_by' => $user->id,
        ]);
        VisitorVisit::create([
            ...$this->visitorData(['full_name' => 'Solo Andry']),
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'created_by' => $user->id,
            'closed_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/reception/visitors')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Visitors/Index')
                ->has('presentVisitors', 1)
                ->where('presentVisitors.0.full_name', 'Eric Randria')
                ->has('recentDepartures', 1)
                ->where('recentDepartures.0.full_name', 'Solo Andry')
            );
    }

    public function test_store_registers_a_non_clinical_visit_and_audits_it(): void
    {
        $user = $this->userWithPermissions(['visitors.create']);

        $response = $this->actingAs($user)->post('/reception/visitors', $this->visitorData());

        $visitor = VisitorVisit::firstOrFail();
        $response->assertRedirect('/reception/visitors');
        $response->assertSessionHas('status', 'Entrée de Eric Randria enregistrée.');
        $this->assertNotNull($visitor->uuid);
        $this->assertSame(VisitorCategory::PatientOrFamilyVisit, $visitor->category);
        $this->assertNotNull($visitor->checked_in_at);
        $this->assertNull($visitor->checked_out_at);
        $this->assertSame($user->id, $visitor->created_by);
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Episode::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'reception',
            'entity_type' => VisitorVisit::class,
            'entity_id' => $visitor->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_professional_visit_requires_and_stores_an_organization(): void
    {
        $user = $this->userWithPermissions(['visitors.create']);
        $professional = $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'reason' => 'Réunion de partenariat.',
        ]);

        $this->actingAs($user)->post('/reception/visitors', $professional)
            ->assertSessionHasErrors('organization');

        $this->actingAs($user)->post('/reception/visitors', [
            ...$professional,
            'organization' => 'Association Santé Madagascar',
        ])->assertRedirect('/reception/visitors');

        $visitor = VisitorVisit::firstOrFail();
        $this->assertSame(VisitorCategory::Professional, $visitor->category);
        $this->assertSame('Association Santé Madagascar', $visitor->organization);
    }

    public function test_professional_visit_can_store_and_privately_display_four_images_or_pdfs(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(['visitors.create', 'visitors.view']);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'organization' => 'Partenaire SARL',
            'reason' => 'Présentation d’un nouveau partenariat.',
            'professional_attachments' => [
                UploadedFile::fake()->image('carte-visite.jpg', 800, 500),
                UploadedFile::fake()->image('brochure.png', 800, 500),
                UploadedFile::fake()->create('presentation.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('catalogue.webp', 800, 500),
            ],
        ]))->assertRedirect('/reception/visitors');

        $visitor = VisitorVisit::firstOrFail();
        $attachments = $visitor->attachments()->orderBy('id')->get();
        $this->assertCount(4, $attachments);
        $this->assertSame(
            ['carte-visite.jpg', 'brochure.png', 'presentation.pdf', 'catalogue.webp'],
            $attachments->pluck('original_name')->all(),
        );
        $attachments->each(fn (VisitorVisitAttachment $attachment) => Storage::disk('local')->assertExists($attachment->path));

        $pdf = $attachments->firstWhere('mime_type', 'application/pdf');
        $this->assertNotNull($pdf);

        $this->actingAs($user)
            ->get("/reception/visitors/{$visitor->uuid}/attachments/{$pdf->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($user)->get('/reception/visitors')
            ->assertInertia(fn ($page) => $page
                ->has('presentVisitors.0.attachments', 4)
                ->where('presentVisitors.0.attachments.0.url', "/reception/visitors/{$visitor->uuid}/attachments/{$attachments->first()->uuid}")
                ->where('presentVisitors.0.attachments.0.is_image', true)
                ->missing('presentVisitors.0.attachments.0.path')
            );

        $this->assertSame(4, VisitorVisitAttachment::count());
        $this->assertSame(4, AuditLog::query()
            ->where('action', 'create')
            ->where('entity_type', VisitorVisitAttachment::class)
            ->count());
    }

    public function test_professional_attachment_requires_visitors_view_and_must_belong_to_the_visit(): void
    {
        Storage::fake('local');
        $uploader = $this->userWithPermissions(['visitors.create']);

        $this->actingAs($uploader)->post('/reception/visitors', $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'organization' => 'Partenaire SARL',
            'professional_attachments' => [UploadedFile::fake()->image('brochure.png')],
        ]));

        $visitor = VisitorVisit::firstOrFail();
        $attachment = $visitor->attachments()->firstOrFail();

        $this->actingAs($uploader)
            ->get("/reception/visitors/{$visitor->uuid}/attachments/{$attachment->uuid}")
            ->assertForbidden();

        $viewPermission = Permission::query()->firstOrCreate(['name' => 'visitors.view']);
        $uploader->role->permissions()->attach($viewPermission);
        $viewer = User::query()->findOrFail($uploader->id);

        $otherVisitor = VisitorVisit::create([
            ...$this->visitorData(['full_name' => 'Autre visiteur']),
            'checked_in_at' => now(),
            'created_by' => $uploader->id,
        ]);

        $this->actingAs($viewer)
            ->get("/reception/visitors/{$otherVisitor->uuid}/attachments/{$attachment->uuid}")
            ->assertNotFound();
    }

    public function test_professional_attachments_are_limited_to_four(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(['visitors.create']);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'organization' => 'Partenaire SARL',
            'professional_attachments' => collect(range(1, 5))
                ->map(fn ($number) => UploadedFile::fake()->image("document-{$number}.jpg"))
                ->all(),
        ]))->assertSessionHasErrors('professional_attachments');

        $this->assertSame(0, VisitorVisit::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachments_are_rejected_for_a_patient_or_family_visit(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(['visitors.create']);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'professional_attachments' => [UploadedFile::fake()->image('carte.png')],
        ]))->assertSessionHasErrors('professional_attachments');

        $this->assertSame(0, VisitorVisit::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_professional_attachments_reject_unsupported_or_oversized_files(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(['visitors.create']);
        $data = $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'organization' => 'Partenaire SARL',
        ]);

        $this->actingAs($user)->post('/reception/visitors', [
            ...$data,
            'professional_attachments' => [UploadedFile::fake()->create('brochure.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ])->assertSessionHasErrors('professional_attachments.0');

        $this->actingAs($user)->post('/reception/visitors', [
            ...$data,
            'professional_attachments' => [UploadedFile::fake()->image('brochure.jpg')->size(5121)],
        ])->assertSessionHasErrors('professional_attachments.0');

        $this->assertSame(0, VisitorVisit::count());
    }

    public function test_patient_visit_can_reference_an_existing_patient_by_uuid(): void
    {
        $user = $this->userWithPermissions(['visitors.create']);
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'patient_uuid' => $patient->uuid,
        ]))->assertRedirect('/reception/visitors');

        $this->assertSame($patient->id, VisitorVisit::firstOrFail()->patient_id);
        $this->assertSame(0, Episode::count());
    }

    public function test_professional_visit_cannot_reference_a_patient(): void
    {
        $user = $this->userWithPermissions(['visitors.create']);
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        $this->actingAs($user)->post('/reception/visitors', $this->visitorData([
            'category' => VisitorCategory::Professional->value,
            'organization' => 'Partenaire SARL',
            'patient_uuid' => $patient->uuid,
        ]))->assertSessionHasErrors('patient_uuid');

        $this->assertSame(0, VisitorVisit::count());
    }

    public function test_close_records_departure_by_uuid_and_audits_it(): void
    {
        $user = $this->userWithPermissions(['visitors.close']);
        $visitor = VisitorVisit::create([
            ...$this->visitorData(),
            'checked_in_at' => now()->subHour(),
            'created_by' => $user->id,
        ]);
        $response = $this->actingAs($user)->post("/reception/visitors/{$visitor->uuid}/close");

        $response->assertRedirect('/reception/visitors');
        $response->assertSessionHas('status', 'Sortie de Eric Randria enregistrée.');
        $visitor->refresh();
        $this->assertNotNull($visitor->checked_out_at);
        $this->assertSame($user->id, $visitor->closed_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'module' => 'reception',
            'entity_type' => VisitorVisit::class,
            'entity_id' => $visitor->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_visit_cannot_be_closed_twice(): void
    {
        $user = $this->userWithPermissions(['visitors.close']);
        $visitor = VisitorVisit::create([
            ...$this->visitorData(),
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'created_by' => $user->id,
            'closed_by' => $user->id,
        ]);

        $originalCheckout = $visitor->checked_out_at;

        $this->actingAs($user)->post("/reception/visitors/{$visitor->uuid}/close")
            ->assertSessionHasErrors('visitor');

        $this->assertTrue($visitor->fresh()->checked_out_at->equalTo($originalCheckout));
    }

    public function test_close_route_does_not_accept_a_local_numeric_id(): void
    {
        $user = $this->userWithPermissions(['visitors.close']);
        $visitor = VisitorVisit::create([
            ...$this->visitorData(),
            'checked_in_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post("/reception/visitors/{$visitor->id}/close")
            ->assertNotFound();

        $this->assertNull($visitor->fresh()->checked_out_at);
    }
}
