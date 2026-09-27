<?php

namespace Tests\Feature\Medicine;

use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TreatmentJournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-118 — tous les journaux de traitement d'un patient, en un seul document.
 *
 * La page réunit ce que `TreatmentJournal` sert déjà pour chaque passage
 * (ADR-116) : elle ne recompose rien, respecte les mêmes permissions par
 * source, et ne permet aucune saisie.
 */
class PatientTreatmentJournalsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names, string $roleCode = 'MEDICINE'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($names as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function nurse(): User
    {
        return $this->userWithPermissions(['patients.view', 'treatment_journal.view', 'treatment_journal.record']);
    }

    private function patient(string $number = 'A-26-0001'): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => 'Florent',
            'last_name' => 'Bezara',
            'birth_date' => '1980-03-04',
            'sex' => 'M',
        ]);
    }

    private function episode(Patient $patient, string $number, string $startedAt, string $status = 'OPEN'): Episode
    {
        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $number,
            'status' => $status,
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => CarbonImmutable::parse($startedAt),
        ]);
    }

    private function line(Episode $episode, User $author, string $at, string $text): void
    {
        TreatmentJournalEntry::create([
            'episode_id' => $episode->id,
            'occurred_at' => CarbonImmutable::parse($at),
            'description' => "<p>{$text}</p>",
            'recorded_by' => $author->id,
        ]);
    }

    public function test_every_passage_of_the_patient_is_served_oldest_first_with_its_own_journal(): void
    {
        $nurse = $this->nurse();
        $patient = $this->patient();
        // Créés dans le désordre : c'est la date de début qui classe, jamais l'ordre d'insertion.
        $recent = $this->episode($patient, 'A-26-0001-02', '2026-09-16 08:00', 'CLOSED');
        $old = $this->episode($patient, 'A-26-0001-01', '2026-09-15 12:25', 'CLOSED');
        $this->episode($patient, 'A-26-0001-03', '2026-09-18 09:00');
        $this->line($old, $nurse, '2026-09-15 13:00', 'Pansement refait');
        $this->line($old, $nurse, '2026-09-15 14:00', 'Injection effectuée');
        $this->line($recent, $nurse, '2026-09-16 09:00', 'Perfusion posée');

        $this->actingAs($nurse)->get("/patients/{$patient->uuid}/journaux-de-traitement")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/PatientTreatmentJournals')
                ->where('patient.uuid', $patient->uuid)
                ->where('patient.patient_number', 'A-26-0001')
                ->where('patient.name', 'Bezara Florent')
                ->has('passages', 3)
                ->where('passages.0.episode_number', 'A-26-0001-01')
                ->where('passages.1.episode_number', 'A-26-0001-02')
                ->where('passages.2.episode_number', 'A-26-0001-03')
                ->where('passages.0.rows_count', 2)
                ->where('passages.1.rows_count', 1)
                ->where('passages.2.rows_count', 0)
                ->has('passages.0.rows', 2)
                ->where('passages.0.rows.0.visa', $nurse->name)
                ->where('passages.2.rows', [])
                ->where('totals.passages', 3)
                ->where('totals.with_rows', 2)
                ->where('totals.rows', 3)
                ->has('generated_at')
            );
    }

    public function test_the_journal_lines_are_chronological_inside_each_passage(): void
    {
        $nurse = $this->nurse();
        $patient = $this->patient();
        $episode = $this->episode($patient, 'A-26-0001-01', '2026-09-15 12:25');
        $this->line($episode, $nurse, '2026-09-15 16:00', 'Troisième');
        $this->line($episode, $nurse, '2026-09-15 13:00', 'Première');
        $this->line($episode, $nurse, '2026-09-15 14:00', 'Deuxième');

        $rows = $this->actingAs($nurse)->get("/patients/{$patient->uuid}/journaux-de-traitement")
            ->viewData('page')['props']['passages'][0]['rows'];

        $this->assertSame(
            ['Première', 'Deuxième', 'Troisième'],
            array_map(fn ($row) => trim(strip_tags($row['description_html'])), $rows),
        );
    }

    public function test_only_this_patients_passages_are_served(): void
    {
        $nurse = $this->nurse();
        $patient = $this->patient();
        $other = $this->patient('A-26-0002');
        $mine = $this->episode($patient, 'A-26-0001-01', '2026-09-15 12:25');
        $theirs = $this->episode($other, 'A-26-0002-01', '2026-09-15 12:25');
        $this->line($mine, $nurse, '2026-09-15 13:00', 'Ma ligne');
        $this->line($theirs, $nurse, '2026-09-15 13:00', 'Ligne d’un autre patient');

        $passages = $this->actingAs($nurse)->get("/patients/{$patient->uuid}/journaux-de-traitement")
            ->viewData('page')['props']['passages'];

        $this->assertSame(['A-26-0001-01'], array_column($passages, 'episode_number'));
        $this->assertStringNotContainsString('autre patient', json_encode($passages));
    }

    /**
     * Une ligne visible sur le journal d'un passage l'est ici, une ligne que
     * l'on ne peut pas voir là ne l'est pas ici non plus : la page réunit, elle
     * n'élargit rien (ADR-054).
     */
    public function test_each_source_keeps_the_permission_that_guards_it_on_the_single_passage_sheet(): void
    {
        $viewer = $this->userWithPermissions(['patients.view', 'treatment_journal.view'], 'VIEWER');
        $patient = $this->patient();
        $episode = $this->episode($patient, 'A-26-0001-01', '2026-09-15 12:25');
        $this->line($episode, $viewer, '2026-09-15 13:00', 'Saisie manuelle');

        $combined = $this->actingAs($viewer)->get("/patients/{$patient->uuid}/journaux-de-traitement")
            ->viewData('page')['props']['passages'][0]['rows'];
        $single = $this->actingAs($viewer)->get("/passages/{$episode->uuid}/journal")
            ->viewData('page')['props']['rows'];

        $this->assertSame($single, $combined);
    }

    public function test_the_page_needs_both_patients_view_and_treatment_journal_view(): void
    {
        $patient = $this->patient();
        $this->episode($patient, 'A-26-0001-01', '2026-09-15 12:25');

        $journalOnly = $this->userWithPermissions(['treatment_journal.view'], 'JOURNAL_ONLY');
        $patientOnly = $this->userWithPermissions(['patients.view'], 'PATIENT_ONLY');

        $this->actingAs($journalOnly)->get("/patients/{$patient->uuid}/journaux-de-traitement")->assertForbidden();
        $this->actingAs($patientOnly)->get("/patients/{$patient->uuid}/journaux-de-traitement")->assertForbidden();
    }

    public function test_a_patient_without_any_passage_gets_an_empty_document_not_an_error(): void
    {
        $nurse = $this->nurse();
        $patient = $this->patient();

        $this->actingAs($nurse)->get("/patients/{$patient->uuid}/journaux-de-traitement")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('passages', [])
                ->where('totals.passages', 0)
                ->where('totals.with_rows', 0)
                ->where('totals.rows', 0)
            );
    }

    public function test_the_page_is_read_only(): void
    {
        $nurse = $this->nurse();
        $patient = $this->patient();

        // Une ligne s'ajoute depuis le journal du passage, jamais depuis le document réuni.
        $this->actingAs($nurse)
            ->post("/patients/{$patient->uuid}/journaux-de-traitement", ['description' => 'x'])
            ->assertStatus(405);
    }
}
