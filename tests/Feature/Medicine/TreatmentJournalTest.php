<?php

namespace Tests\Feature\Medicine;

use App\Enums\EpisodeStatus;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TreatmentJournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-116 — le « DOSSIER MÉDICAL – TRAITEMENT » de la clinique.
 *
 * Deux sources sur la même feuille : ce qui est déjà consigné ailleurs dans
 * le passage (lu, jamais recopié), et ce que Médecine/Soins ajoutent à la
 * main pour ce que l'application n'enregistre pas encore.
 */
class TreatmentJournalTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names, string $roleCode = 'MEDICINE'): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function doctor(): User
    {
        return $this->userWithPermissions([
            'treatment_journal.view', 'treatment_journal.record', 'consultations.view',
        ]);
    }

    private function episode(User $actor, EpisodeStatus $status = EpisodeStatus::Open): Episode
    {
        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Herizo',
            'last_name' => 'Andria',
            'birth_date' => '1985-02-10',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => $status,
            'started_at' => now()->subHours(2),
            'created_by' => $actor->id,
        ]);
    }

    public function test_a_manually_recorded_entry_appears_on_the_sheet(): void
    {
        $doctor = $this->doctor();
        $episode = $this->episode($doctor);

        $this->actingAs($doctor)->post("/passages/{$episode->uuid}/journal", [
            'occurred_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
            'description' => '<p>Perfusion posée, <strong>500 mL</strong> sérum salé.</p>',
        ])->assertSessionHasNoErrors();

        $entry = TreatmentJournalEntry::query()->sole();
        $this->assertSame($episode->id, $entry->episode_id);
        $this->assertSame($doctor->id, $entry->recorded_by);
        $this->assertSame('<p>Perfusion posée, <strong>500 mL</strong> sérum salé.</p>', $entry->description);

        $rows = $this->actingAs($doctor)->get("/passages/{$episode->uuid}/journal")
            ->assertOk()
            ->viewData('page')['props']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('MANUAL', $rows[0]['source']);
        $this->assertSame($doctor->name, $rows[0]['visa']);
        $this->assertStringContainsString('Perfusion posée', $rows[0]['description_html']);
    }

    /** Une ligne vide ne dit rien : elle ne peut jamais être enregistrée. */
    public function test_a_blank_entry_is_refused(): void
    {
        $doctor = $this->doctor();
        $episode = $this->episode($doctor);

        $this->actingAs($doctor)->post("/passages/{$episode->uuid}/journal", [
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => '<p><br></p>',
        ])->assertSessionHasErrors('description');

        $this->assertSame(0, TreatmentJournalEntry::query()->count());
    }

    /**
     * La chronologie relit ce qui est déjà consigné ailleurs : rien n'est
     * recopié, une correction de la source se voit donc ici aussi.
     */
    public function test_automatic_rows_are_read_from_what_is_already_recorded(): void
    {
        $doctor = $this->doctor();
        $episode = $this->episode($doctor);

        Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'chief_complaint' => 'Fièvre depuis 3 jours',
            'reason' => '<p>Fièvre, frissons.</p>',
            'consulted_at' => now()->subHour(),
        ]);

        $rows = $this->actingAs($doctor)->get("/passages/{$episode->uuid}/journal")
            ->viewData('page')['props']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('CONSULTATION', $rows[0]['source']);
        $this->assertStringContainsString('Fièvre depuis 3 jours', $rows[0]['description']);
        $this->assertSame($doctor->name, $rows[0]['visa']);

        // Toujours aucune ligne manuelle : la consultation n'a jamais été
        // recopiée dans `treatment_journal_entries`.
        $this->assertSame(0, TreatmentJournalEntry::query()->count());
    }

    /** Un passage clos par la sortie administrative (ADR-090) ne reçoit plus rien. */
    public function test_a_closed_passage_refuses_new_entries(): void
    {
        $doctor = $this->doctor();
        $episode = $this->episode($doctor, EpisodeStatus::Closed);

        $this->actingAs($doctor)->post("/passages/{$episode->uuid}/journal", [
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'Une ligne tardive.',
        ])->assertSessionHasErrors('description');

        $this->assertSame(0, TreatmentJournalEntry::query()->count());
    }

    public function test_viewing_requires_its_own_permission(): void
    {
        $noAccess = $this->userWithPermissions(['consultations.view']);
        $episode = $this->episode($noAccess);

        $this->actingAs($noAccess)->get("/passages/{$episode->uuid}/journal")->assertForbidden();
    }

    public function test_recording_requires_its_own_permission(): void
    {
        $readOnly = $this->userWithPermissions(['treatment_journal.view']);
        $episode = $this->episode($readOnly);

        $this->actingAs($readOnly)->post("/passages/{$episode->uuid}/journal", [
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'Tentative sans droit.',
        ])->assertForbidden();

        $this->assertSame(0, TreatmentJournalEntry::query()->count());
    }
}
