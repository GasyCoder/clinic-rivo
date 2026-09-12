<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CareRecord;
use App\Models\CareRecordDraft;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entry in progress on a nursing worksheet must survive a page reload:
 * only an explicit discard, or a real save, may throw it away.
 */
class CareRecordDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_typing_survives_a_reload_and_is_restored_to_its_author(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        $this->actingAs($nurse)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => [
                    'blood_pressure_systolic' => '145',
                    // Deliberately incomplete: an unfinished pair is exactly
                    // what has to survive, and would fail real validation.
                    'blood_pressure_diastolic' => '',
                    'temperature_celsius' => '38,2',
                    'transmission_reason' => 'Contrôler la température',
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['saved_at']);

        $draft = CareRecordDraft::query()->sole();
        $this->assertSame($orientation->id, $draft->episode_orientation_id);
        $this->assertSame($nurse->id, $draft->created_by);
        $this->assertSame('145', $draft->payload['blood_pressure_systolic']);

        // Reloading the page hands it straight back.
        $this->actingAs($nurse)->get(route('care.orientations.show', $orientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Show')
                ->where('careRecordDraft.payload.blood_pressure_systolic', '145')
                ->where('careRecordDraft.payload.temperature_celsius', '38,2')
                ->where('careRecordDraft.payload.transmission_reason', 'Contrôler la température')
            );
    }

    /**
     * Regression: Laravel's ConvertEmptyStringsToNull turned every empty
     * field into null, and the restored null then broke the form's own
     * .trim() calls — blanking the last step of the worksheet. A draft is a
     * faithful snapshot of the screen, so an empty field stays an empty
     * string.
     */
    public function test_an_empty_field_stays_an_empty_string_and_never_becomes_null(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        $this->actingAs($nurse)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => [
                    'blood_pressure_systolic' => '145',
                    'blood_pressure_diastolic' => '',
                    'no_procedure_reason' => '',
                    'transmission_reason' => '',
                    'consumable_notes' => '',
                ],
            ])
            ->assertOk();

        $payload = CareRecordDraft::query()->sole()->payload;

        foreach (['blood_pressure_diastolic', 'no_procedure_reason', 'transmission_reason', 'consumable_notes'] as $field) {
            $this->assertSame('', $payload[$field], "{$field} ne doit jamais devenir null");
        }

        $this->assertSame('145', $payload['blood_pressure_systolic']);
    }

    public function test_a_second_save_replaces_the_draft_instead_of_stacking_them(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        foreach (['70', '82'] as $value) {
            $this->actingAs($nurse)
                ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                    'payload' => ['heart_rate' => $value],
                ])
                ->assertOk();
        }

        $this->assertDatabaseCount('care_record_drafts', 1);
        $this->assertSame('82', CareRecordDraft::query()->sole()->payload['heart_rate']);
    }

    public function test_another_nurse_never_inherits_an_unvalidated_entry(): void
    {
        $author = $this->nurse();
        $colleague = $this->nurse();
        $orientation = $this->orientation($author);

        $this->actingAs($author)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => ['heart_rate' => '58'],
            ])
            ->assertOk();

        // Same visit, different account: the entry is not theirs to save.
        $this->actingAs($colleague)->get(route('care.orientations.show', $orientation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('careRecordDraft', null));

        // And their own draft is a separate row.
        $this->actingAs($colleague)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => ['heart_rate' => '95'],
            ])
            ->assertOk();

        $this->assertDatabaseCount('care_record_drafts', 2);
    }

    public function test_only_whitelisted_worksheet_fields_are_kept(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        $this->actingAs($nurse)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => [
                    'spo2' => '96',
                    // Neither is a worksheet field; a draft must never become
                    // a channel for arbitrary stored data.
                    'hospitalized_at' => '2026-09-10',
                    'orient_to_medicine' => true,
                ],
            ])
            ->assertOk();

        $payload = CareRecordDraft::query()->sole()->payload;
        $this->assertSame(['spo2'], array_keys($payload));
    }

    public function test_saving_the_record_clears_its_draft(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        $this->actingAs($nurse)->putJson("/care/orientations/{$orientation->uuid}/draft", [
            'payload' => ['heart_rate' => '58'],
        ])->assertOk();

        $this->actingAs($nurse)->put("/care/orientations/{$orientation->uuid}/record", [
            'heart_rate' => 58,
        ])->assertRedirect();

        $this->assertSame(58, CareRecord::query()->sole()->heart_rate);
        $this->assertDatabaseCount('care_record_drafts', 0);
    }

    public function test_the_nurse_can_discard_their_entry_explicitly(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);

        $this->actingAs($nurse)->putJson("/care/orientations/{$orientation->uuid}/draft", [
            'payload' => ['heart_rate' => '58'],
        ])->assertOk();

        $this->actingAs($nurse)
            ->delete("/care/orientations/{$orientation->uuid}/draft")
            ->assertRedirect(route('care.orientations.show', $orientation));

        $this->assertDatabaseCount('care_record_drafts', 0);
        // Discarding typing never creates a clinical record.
        $this->assertSame(0, CareRecord::query()->count());
    }

    public function test_a_read_only_account_cannot_autosave(): void
    {
        $observer = $this->userWithPermissions(['care.view'], 'CARE_OBSERVER');
        $orientation = $this->orientation($this->nurse());

        $this->actingAs($observer)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => ['heart_rate' => '58'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('care_record_drafts', 0);
    }

    public function test_a_draft_is_refused_once_the_visit_is_no_longer_in_progress(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->orientation($nurse);
        $orientation->update(['status' => 'COMPLETED', 'completed_at' => now()]);

        $this->actingAs($nurse)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => ['heart_rate' => '58'],
            ])
            ->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function nurse(): User
    {
        return $this->userWithPermissions([
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
    }

    private function orientation(User $nurse): EpisodeOrientation
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]));
        $act = CatalogItem::query()->firstOrCreate(
            ['code' => 'PANSEMENT-DRAFT'],
            [
                'name' => 'Pansement simple',
                'type' => CatalogItemType::Service,
                'module' => CatalogModule::Care,
                'unit' => 'soin',
                'billable' => true,
                'stockable' => false,
                'reception_selectable' => true,
                'reception_routing_mode' => ReceptionRoutingMode::CareOnly,
                'created_by' => $nurse->id,
                'updated_by' => $nurse->id,
            ],
        );
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $act->uuid,
            'quantity' => 1,
        ]], $nurse);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $nurse);

        return $orientation->fresh();
    }

    private function userWithPermissions(array $permissions, string $roleCode = 'NURSE'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
