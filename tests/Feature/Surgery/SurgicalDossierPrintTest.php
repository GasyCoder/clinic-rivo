<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Enums\SurgicalAwakeningStatus;
use App\Enums\SurgicalTreatmentCategory;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Medicine\TreatmentJournal;
use App\Support\Documents\MedicalRecordSheet;
use App\Support\Documents\SurgicalDossierSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Surgery\Concerns\BuildsSurgicalCases;
use Tests\TestCase;

/**
 * ADR-172 — le « Dossier chirurgical » imprimable, généré depuis les données,
 * et ce que le journal de traitement et le dossier médical disent du bloc.
 */
class SurgicalDossierPrintTest extends TestCase
{
    use BuildsSurgicalCases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
    }

    /** @return array{0: SurgicalRequest, 1: User, 2: User} */
    private function operatedCase(): array
    {
        $surgeon = $this->surgeonUser();
        $anesthetist = $this->anesthetistUser();
        $case = $this->caseReadyForIncision($surgeon, $anesthetist);

        $case->anesthesiaRecord()->update([
            'consultation_data' => [
                'admission_reason' => 'Douleur en fosse iliaque droite',
                'medical_conditions' => ['HYPERTENSION', 'ASTHMA'],
                'neuropsychological_status' => 'ANXIOUS',
                'blood_pressure_systolic' => 130, 'blood_pressure_diastolic' => 85,
                'last_meal_time' => '19:30',
            ],
            'paraclinical_data' => [
                'laboratory' => ['hemoglobin' => '12,4 g/dl'],
                'blood_group' => 'O', 'rhesus' => 'POSITIVE',
                'glasgow_eye' => 4, 'glasgow_verbal' => 5, 'glasgow_motor' => 6,
                'asa_class' => 'ASA II',
                'conclusion' => 'Apte au bloc.',
            ],
        ]);

        $case->blockEntry()->create([
            'height_cm' => 172, 'weight_kg' => 70.5, 'temperature_celsius' => 36.8,
            'blood_pressure_systolic' => 125, 'blood_pressure_diastolic' => 80,
            'full_bath_completed' => true, 'peripheral_iv_count' => 1,
            'serum_name' => 'Ringer', 'serum_quantity' => 500, 'serum_unit' => 'ml',
            'created_by' => $surgeon->id,
        ]);
        $case->treatmentItems()->create([
            'phase' => SurgicalTreatmentPhase::Preliminary, 'category' => SurgicalTreatmentCategory::Antibiotic,
            'label' => 'Céfazoline', 'quantity' => 2, 'unit' => 'g', 'recorded_by' => $surgeon->id,
        ]);

        $this->app->make(CreateSurgicalInterventionAction::class)
            ->execute($case->fresh(), ['started_at' => '2026-09-22 09:15:00'], $surgeon)
            ->update(['procedure_summary' => 'Appendicectomie par Mac Burney.']);

        $case->fresh()->blockExit()->create([
            'block_entered_at' => '2026-09-22 09:00:00',
            'block_exited_at' => '2026-09-22 10:30:00',
            'entry_blood_pressure_systolic' => 120, 'entry_blood_pressure_diastolic' => 78,
            'exit_blood_pressure_systolic' => 118, 'exit_blood_pressure_diastolic' => 76,
            'awakening_status' => SurgicalAwakeningStatus::PerfectlyAwake, 'awakening_score' => 10,
            'created_by' => $surgeon->id,
        ]);
        $case->complications()->create([
            'description' => 'Nausées au réveil', 'reported_by' => $surgeon->id, 'reported_at' => '2026-09-22 10:45:00',
        ]);

        return [$case->fresh(), $surgeon, $anesthetist];
    }

    public function test_the_dossier_has_four_sheets_read_from_what_was_recorded(): void
    {
        [$case, $surgeon] = $this->operatedCase();
        $this->grant($surgeon, ['anesthesia.view']);

        $dossier = $this->app->make(SurgicalDossierSheet::class)->present($case, $surgeon->fresh());

        $this->assertSame(['entry', 'exit', 'consultation', 'paraclinical'], array_column($dossier['sheets'], 'key'));
        $this->assertSame('Appendicectomie', $dossier['header']['procedure']);
        $this->assertSame('Rakoto Jean', $dossier['header']['patient']['name']);
        $this->assertSame($surgeon->name, $dossier['header']['surgeon']);

        $entry = $this->rowsOf($dossier['sheets'][0]);
        $this->assertSame('125 / 80', $entry['Tension artérielle (mmHg)']);
        $this->assertSame('70,5', $entry['Poids (kg)']);
        $this->assertSame('Oui', $entry['Toilette complète']);
        // Une case que personne n'a remplie reste vide — jamais « Non » (ADR-077).
        $this->assertNull($entry['Pesage réalisé']);
        $this->assertSame('500 ml', $entry['Quantité']);
        $this->assertSame([['Antibiotique', 'Céfazoline', '2 g']], $this->tableOf($dossier['sheets'][0], 'Traitement préliminaire'));

        $exit = $this->rowsOf($dossier['sheets'][1]);
        $this->assertSame('22/09/2026 09:00', $exit['Entrée au bloc']);
        $this->assertSame('22/09/2026 09:15', $exit['Début de l’intervention']);
        $this->assertSame('Parfaitement réveillé', $exit['État de réveil']);
        $this->assertSame([['22/09/2026 10:45', 'Nausées au réveil', $surgeon->name]], $this->tableOf($dossier['sheets'][1], 'Complications'));

        $consultation = $this->rowsOf($dossier['sheets'][2]);
        $this->assertSame('HTA, Asthme', $consultation['Antécédents médicaux']);
        $this->assertSame('Anxieux(se)', $consultation['État neuropsychologique']);
        $this->assertSame('130 / 85', $consultation['Tension artérielle (mmHg)']);

        $paraclinical = $this->rowsOf($dossier['sheets'][3]);
        $this->assertSame('O +', $paraclinical['Groupe sanguin']);
        $this->assertSame('15', $paraclinical['Glasgow — total']);
        // La case « Autorisation d'opérer » du papier est la décision de l'ADR-170.
        $this->assertSame('Autorisé', $paraclinical['Décision anesthésique']);
        $this->assertArrayNotHasKey('Autorisation d’opérer (ancienne saisie)', $paraclinical);
    }

    public function test_each_sheet_is_gated_by_the_right_that_owns_it(): void
    {
        [$case, $surgeon, $anesthetist] = $this->operatedCase();

        // Le chirurgien, sans `anesthesia.view` : les deux feuilles d'anesthésie se nomment restreintes.
        $forSurgeon = $this->app->make(SurgicalDossierSheet::class)->present($case, $surgeon);
        $this->assertNull($forSurgeon['sheets'][0]['restricted']);
        $this->assertSame('anesthesia.view', $forSurgeon['sheets'][2]['restricted']);
        $this->assertSame([], $forSurgeon['sheets'][2]['sections']);
        $this->assertFalse($forSurgeon['header']['anesthetist_visible']);
        $this->assertNull($forSurgeon['header']['anesthetist']);

        // L'anesthésiste, sans `surgery.view` : les deux feuilles du bloc se nomment restreintes.
        $forAnesthetist = $this->app->make(SurgicalDossierSheet::class)->present($case, $anesthetist);
        $this->assertSame('surgery.view', $forAnesthetist['sheets'][0]['restricted']);
        $this->assertNull($forAnesthetist['sheets'][3]['restricted']);
        $this->assertSame($anesthetist->name, $forAnesthetist['header']['anesthetist']);
    }

    public function test_the_route_opens_to_the_block_and_to_the_anesthesia_and_can_print_one_sheet(): void
    {
        [$case, $surgeon, $anesthetist] = $this->operatedCase();

        $this->actingAs($surgeon)
            ->get("/surgery/{$case->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Surgery/DossierPrint')
                ->where('dossier.sheet', null)
                ->has('dossier.sheets', 4)
                ->where('back.href', "/surgery/{$case->uuid}"));

        $this->actingAs($anesthetist)
            ->get("/surgery/{$case->uuid}/dossier?feuille=paraclinical&from=anesthesia")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dossier.sheet', 'paraclinical')
                ->has('dossier.sheets', 1)
                ->where('dossier.sheets.0.key', 'paraclinical')
                ->where('back.href', "/anesthesia/{$case->uuid}"));

        // Une feuille inconnue ne filtre rien : tout le dossier, jamais une page vide.
        $this->actingAs($surgeon)
            ->get("/surgery/{$case->uuid}/dossier?feuille=inconnue")
            ->assertInertia(fn ($page) => $page->where('dossier.sheet', null)->has('dossier.sheets', 4));

        $this->actingAs($this->caseUser('RECEPTION', 'Réception', 'RECEPTIONIST', 'Réceptionniste', ['patients.view']))
            ->get("/surgery/{$case->uuid}/dossier")
            ->assertForbidden();
    }

    public function test_the_treatment_journal_lists_what_the_block_did_gated_by_surgery_view(): void
    {
        [$case, $surgeon] = $this->operatedCase();
        $this->grant($surgeon, ['treatment_journal.view']);

        $rows = collect($this->app->make(TreatmentJournal::class)->rows($case->episode, $surgeon->fresh()))
            ->where('source', 'SURGERY');

        $descriptions = $rows->pluck('description')->all();
        $this->assertContains('Entrée au bloc opératoire — Appendicectomie', $descriptions);
        $this->assertContains('Début de l’intervention : Appendicectomie — Appendicectomie par Mac Burney.', $descriptions);
        $this->assertContains('Sortie du bloc opératoire — réveil : Parfaitement réveillé', $descriptions);
        $this->assertContains('Traitement préliminaire : Céfazoline × 2 g — Antibiotique', $descriptions);
        $this->assertContains('Complication : Nausées au réveil', $descriptions);
        // Chronologique : l'entrée au bloc (09:00) précède l'incision (09:15).
        $this->assertTrue(array_search('Entrée au bloc opératoire — Appendicectomie', $descriptions, true)
            < array_search('Début de l’intervention : Appendicectomie — Appendicectomie par Mac Burney.', $descriptions, true));

        $nurse = $this->caseUser('NURSE', 'Soins', 'REGISTERED_NURSE', 'Infirmier', ['treatment_journal.view']);
        $this->assertSame(0, collect($this->app->make(TreatmentJournal::class)->rows($case->episode, $nurse))->where('source', 'SURGERY')->count());
    }

    public function test_the_medical_record_sheet_has_a_block_section_gated_per_right(): void
    {
        [$case, $surgeon, $anesthetist] = $this->operatedCase();
        $this->grant($surgeon, ['patients.view']);

        $sheet = $this->app->make(MedicalRecordSheet::class)->present($case->episode, $surgeon->fresh());

        $this->assertTrue($sheet['surgery_visible']);
        $this->assertCount(1, $sheet['surgery']);
        $item = $sheet['surgery'][0];
        $this->assertSame('Appendicectomie', $item['procedure']);
        $this->assertSame($surgeon->name, $item['surgeon']);
        $this->assertSame('Parfaitement réveillé', $item['awakening']);
        // Sans `anesthesia.view`, l'anesthésie n'est pas servie — jamais servie vide.
        $this->assertFalse($item['anesthesia_visible']);
        $this->assertNull($item['anesthesia']);

        $this->grant($anesthetist, ['patients.view', 'surgery.view']);
        $forAnesthetist = $this->app->make(MedicalRecordSheet::class)->present($case->episode, $anesthetist->fresh());
        $this->assertSame('ASA II', $forAnesthetist['surgery'][0]['anesthesia']['asa_class']);
        $this->assertSame('Autorisé', $forAnesthetist['surgery'][0]['anesthesia']['clearance']);

        $reception = $this->caseUser('RECEPTION', 'Réception', 'RECEPTIONIST', 'Réceptionniste', ['patients.view']);
        $forReception = $this->app->make(MedicalRecordSheet::class)->present($case->episode, $reception);
        $this->assertFalse($forReception['surgery_visible']);
        $this->assertNull($forReception['surgery']);
    }

    /** @return array<string, string|null> */
    private function rowsOf(array $sheet): array
    {
        $rows = [];
        foreach ($sheet['sections'] as $section) {
            if ($section['type'] === 'rows') {
                foreach ($section['rows'] as $row) {
                    $rows[$row['label']] = $row['value'];
                }
            }
        }

        return $rows;
    }

    /** @return list<list<string|null>> */
    private function tableOf(array $sheet, string $title): array
    {
        foreach ($sheet['sections'] as $section) {
            if ($section['type'] === 'table' && $section['title'] === $title) {
                return $section['rows'];
            }
        }

        $this->fail("Table « {$title} » absente.");
    }
}
