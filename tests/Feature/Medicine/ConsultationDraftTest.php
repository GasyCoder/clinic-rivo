<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\ConsultationDraft;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everything typed across the consultation wizard must survive a reload:
 * only an explicit discard, or a real save, may throw it away (ADR-073).
 */
class ConsultationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_typing_across_several_forms_survives_a_reload(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
                'payload' => [
                    'consultation' => [
                        'reason' => '<p>Céphalées</p>',
                        // Deliberately half-written: this is exactly what
                        // has to survive and would fail real validation.
                        'clinical_exam' => '<p>Nuque',
                    ],
                    'diagnosis' => ['description' => 'Hypothèse : migraine'],
                    'discharge' => ['recommendations' => ''],
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['saved_at']);

        $draft = ConsultationDraft::query()->sole();
        $this->assertSame($orientation->id, $draft->episode_orientation_id);
        $this->assertSame($doctor->id, $draft->created_by);
        // Empty stays empty: the draft is a faithful snapshot of the screen.
        $this->assertSame('', $draft->payload['discharge']['recommendations']);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Show')
                ->where('consultationDraft.payload.consultation.reason', '<p>Céphalées</p>')
                ->where('consultationDraft.payload.consultation.clinical_exam', '<p>Nuque')
                ->where('consultationDraft.payload.diagnosis.description', 'Hypothèse : migraine')
            );
    }

    public function test_only_whitelisted_wizard_sections_are_kept(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
                'payload' => [
                    'consultation' => ['reason' => '<p>Test</p>'],
                    // Not a wizard form; a draft must never become a channel
                    // for arbitrary stored data.
                    'billing' => ['amount' => 999],
                ],
            ])
            ->assertOk();

        $this->assertSame(['consultation'], array_keys(ConsultationDraft::query()->sole()->payload));
    }

    public function test_another_doctor_never_inherits_an_unvalidated_entry(): void
    {
        $author = $this->doctor();
        $colleague = $this->doctor();
        [, $orientation] = $this->consultation($author);

        $this->actingAs($author)->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
            'payload' => ['consultation' => ['reason' => '<p>Céphalées</p>']],
        ])->assertOk();

        $this->actingAs($colleague)
            ->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('consultationDraft', null));

        $this->actingAs($colleague)->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
            'payload' => ['consultation' => ['reason' => '<p>Autre motif</p>']],
        ])->assertOk();

        $this->assertDatabaseCount('consultation_drafts', 2);
    }

    public function test_a_second_save_replaces_the_draft_instead_of_stacking_them(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        foreach (['<p>Un</p>', '<p>Deux</p>'] as $reason) {
            $this->actingAs($doctor)->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
                'payload' => ['consultation' => ['reason' => $reason]],
            ])->assertOk();
        }

        $this->assertDatabaseCount('consultation_drafts', 1);
        $this->assertSame(
            '<p>Deux</p>',
            ConsultationDraft::query()->sole()->payload['consultation']['reason'],
        );
    }

    public function test_the_doctor_can_discard_the_entry_without_creating_anything(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
            'payload' => ['consultation' => ['reason' => '<p>Céphalées</p>']],
        ])->assertOk();

        $this->actingAs($doctor)
            ->from("/medicine/orientations/{$orientation->uuid}/consultation")
            ->delete("/medicine/orientations/{$orientation->uuid}/draft")
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/consultation");

        $this->assertDatabaseCount('consultation_drafts', 0);
        // Discarding typing never writes to the consultation: its reason is
        // still the one seeded from the arrival designation, not the draft.
        $this->assertSame('Consultation générale', Consultation::query()->sole()->reason);
    }

    public function test_a_read_only_account_cannot_autosave(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $observer = $this->userWithPermissions(
            ['consultations.view', 'medical_record.view'],
            'MEDICINE_OBSERVER',
        );

        $this->actingAs($observer)
            ->putJson("/medicine/orientations/{$orientation->uuid}/draft", [
                'payload' => ['consultation' => ['reason' => '<p>Test</p>']],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('consultation_drafts', 0);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function doctor(): User
    {
        return $this->userWithPermissions([
            'medical_record.view', 'consultations.view', 'consultations.create',
            'consultations.update', 'patients.view',
        ], 'MEDICINE');
    }

    private function userWithPermissions(array $names, string $roleCode): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function consultation(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
