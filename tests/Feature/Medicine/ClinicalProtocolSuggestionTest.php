<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\DiagnosticCatalog;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Medicine\ClinicalProtocolMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Medicine\Concerns\BuildsClinicalSuggestionFixtures;
use Tests\TestCase;

/**
 * ADR-111 — le système propose, le médecin décide.
 *
 * Ce que ces tests protègent : une proposition vient toujours d'un protocole
 * écrit par la clinique et dit pourquoi elle est faite ; elle ne devient un
 * fait clinique que retenue par le médecin ; et sa trace d'origine ne peut
 * pas être forgée.
 */
class ClinicalProtocolSuggestionTest extends TestCase
{
    use BuildsClinicalSuggestionFixtures, RefreshDatabase;

    public function test_a_diagnosis_is_proposed_from_signs_found_in_the_interview_and_exam(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $malaria = $this->diagnostic('B54', 'Paludisme');
        $this->protocol($doctor, $malaria, ['Fièvre', 'Frissons', 'Céphalées']);

        // Accents et majuscules ne comptent pas : « fievre » est « Fièvre ».
        $consultation->update([
            'chief_complaint' => 'FIEVRE depuis 3 jours',
            'reason' => '<p>Le patient décrit des <strong>frissons</strong> le soir.</p>',
        ]);

        $suggestions = app(ClinicalProtocolMatcher::class)->suggestDiagnoses($consultation->fresh());

        $this->assertCount(1, $suggestions);
        $this->assertSame('Paludisme', $suggestions[0]['name']);
        // Pourquoi il est proposé : les signes retrouvés, et combien il en attendait.
        $this->assertSame(['Fièvre', 'Frissons'], $suggestions[0]['matched_signs']);
        $this->assertSame(3, $suggestions[0]['total_signs']);
    }

    public function test_a_sign_matches_whole_words_only(): void
    {
        $doctor = $this->doctor();
        [, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $this->protocol($doctor, $this->diagnostic('R05', 'Toux'), ['toux']);

        $consultation->update(['chief_complaint' => 'Prescription de touxine demandée']);

        $this->assertSame([], app(ClinicalProtocolMatcher::class)->suggestDiagnoses($consultation->fresh()));
    }

    public function test_a_diagnosis_already_recorded_is_not_proposed_again(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $malaria = $this->diagnostic('B54', 'Paludisme');
        $this->protocol($doctor, $malaria, ['fièvre']);
        $consultation->update(['chief_complaint' => 'fièvre']);

        $this->recordDiagnosis($doctor, $orientation, $malaria);

        $this->assertSame([], app(ClinicalProtocolMatcher::class)->suggestDiagnoses($consultation->fresh()));
    }

    /** Un protocole pédiatrique ne s'applique pas « faute de mieux ». */
    public function test_a_bounded_protocol_is_excluded_when_the_age_is_unknown_and_says_why(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: null);
        $malaria = $this->diagnostic('B54', 'Paludisme');
        $this->protocol($doctor, $malaria, ['fièvre'], ['min_age_years' => 1, 'max_age_years' => 14, 'name' => 'Paludisme — enfant']);
        $consultation->update(['chief_complaint' => 'fièvre']);

        $matcher = app(ClinicalProtocolMatcher::class);
        $this->assertSame([], $matcher->suggestDiagnoses($consultation->fresh()));

        $this->recordDiagnosis($doctor, $orientation, $malaria);
        $prescription = $matcher->suggestPrescription($consultation->fresh());

        $this->assertSame([], $prescription['protocols']);
        $this->assertSame('Paludisme — enfant', $prescription['excluded'][0]['name']);
        $this->assertStringContainsString('âge inconnu', $prescription['excluded'][0]['reason']);
    }

    public function test_the_prescription_of_a_recorded_diagnosis_is_proposed_with_its_allergy_conflict(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $angina = $this->diagnostic('J03', 'Angine');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $this->protocol($doctor, $angina, [], lines: [[
            'medicine_uuid' => $amoxicillin->catalogItem->uuid,
            'dosage' => '500 mg', 'frequency' => '3 fois/jour', 'duration' => '7 jours',
        ]]);
        $consultation->episode->patient->allergies()->create(['substance' => 'amoxicilline', 'recorded_by' => $doctor->id]);

        $this->recordDiagnosis($doctor, $orientation, $angina);

        $line = app(ClinicalProtocolMatcher::class)->suggestPrescription($consultation->fresh())['protocols'][0]['lines'][0];

        $this->assertSame($amoxicillin->catalogItem->uuid, $line['medicine_uuid']);
        $this->assertSame('500 mg', $line['dosage']);
        // Signalée, jamais tue : le médecin la voit avant de décider.
        $this->assertSame('amoxicilline', $line['allergy_conflict']);
    }

    /** Une proposition n'est rien tant qu'elle n'est pas retenue — puis elle est tracée. */
    public function test_retaining_a_proposed_diagnosis_records_its_protocol_of_origin(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $malaria = $this->diagnostic('B54', 'Paludisme');
        $protocol = $this->protocol($doctor, $malaria, ['fièvre']);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
                'type' => 'FINAL',
                'diagnostic_catalog_uuid' => $malaria->uuid,
                'suggestion_protocol_uuid' => $protocol->uuid,
                'return_step' => 'examen',
            ])
            ->assertSessionHasNoErrors();

        $diagnosis = $consultation->diagnoses()->sole();

        $this->assertSame(ClinicalSuggestionSource::Protocol, $diagnosis->suggestion_source);
        $this->assertSame($protocol->id, $diagnosis->clinical_protocol_id);
        // Le médecin reste l'auteur.
        $this->assertSame($doctor->id, $diagnosis->recorded_by);
    }

    public function test_a_forged_diagnosis_origin_is_refused(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $malaria = $this->diagnostic('B54', 'Paludisme');
        $angina = $this->diagnostic('J03', 'Angine');
        $anginaProtocol = $this->protocol($doctor, $angina, ['gorge']);

        // Le protocole de l'angine n'a jamais proposé le paludisme.
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
                'type' => 'FINAL',
                'diagnostic_catalog_uuid' => $malaria->uuid,
                'suggestion_protocol_uuid' => $anginaProtocol->uuid,
            ])
            ->assertSessionHasErrors('suggestion_protocol_uuid');

        $this->assertSame(0, $consultation->diagnoses()->count());
    }

    public function test_a_prescription_line_keeps_its_protocol_of_origin_even_when_adjusted(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $angina = $this->diagnostic('J03', 'Angine');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $protocol = $this->protocol($doctor, $angina, [], lines: [[
            'medicine_uuid' => $amoxicillin->catalogItem->uuid,
            'dosage' => '500 mg', 'frequency' => '3 fois/jour', 'duration' => '7 jours',
        ]]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $amoxicillin->catalogItem->uuid,
                    // Ajustée par le médecin : 10 jours au lieu de 7.
                    'quantity' => 30,
                    'dosage' => '500 mg',
                    'frequency' => '3 fois/jour',
                    'duration' => '10 jours',
                    'suggestion_protocol_uuid' => $protocol->uuid,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $line = $consultation->prescriptions()->sole()->lines()->sole();

        $this->assertSame(ClinicalSuggestionSource::Protocol, $line->suggestion_source);
        $this->assertSame($protocol->id, $line->clinical_protocol_id);
        $this->assertSame('10 jours', $line->duration);
    }

    public function test_a_prescription_line_cannot_claim_a_protocol_that_does_not_prescribe_it(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $paracetamol = $this->medicine($doctor, 'Paracétamol 500 mg', 'Paracétamol');
        $protocol = $this->protocol($doctor, $this->diagnostic('J03', 'Angine'), [], lines: [[
            'medicine_uuid' => $paracetamol->catalogItem->uuid, 'frequency' => '3 fois/jour',
        ]]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
                'lines' => [[
                    'manual' => false,
                    'medicine_uuid' => $amoxicillin->catalogItem->uuid,
                    'quantity' => 21,
                    'dosage' => '500 mg',
                    'frequency' => '3 fois/jour',
                    'suggestion_protocol_uuid' => $protocol->uuid,
                ]],
            ])
            ->assertSessionHasErrors('lines.0.suggestion_protocol_uuid');

        $this->assertSame(0, $consultation->prescriptions()->count());
    }

    public function test_the_consultation_screen_receives_the_suggestions(): void
    {
        $doctor = $this->doctor();
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $this->protocol($doctor, $this->diagnostic('B54', 'Paludisme'), ['fièvre']);
        $consultation->update(['chief_complaint' => 'fièvre']);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clinical_suggestions.protocol_count', 1)
                ->where('clinical_suggestions.diagnoses.0.name', 'Paludisme')
            );
    }

    public function test_writing_a_protocol_requires_its_own_permission(): void
    {
        $reader = $this->doctor(manage: false);
        $diagnostic = $this->diagnostic('B54', 'Paludisme');
        $medicine = $this->medicine($reader, 'Artéméther', 'Artéméther');

        $this->actingAs($reader)->get('/medicine/protocoles')->assertOk();
        $this->actingAs($reader)->get('/medicine/protocoles/nouveau')->assertForbidden();
        $this->actingAs($reader)
            ->post('/medicine/protocoles', $this->payload($diagnostic, $medicine))
            ->assertForbidden();

        $this->assertSame(0, ClinicalProtocol::query()->count());
    }

    public function test_a_protocol_is_saved_with_its_lines_and_audited(): void
    {
        $doctor = $this->doctor();
        $diagnostic = $this->diagnostic('B54', 'Paludisme');
        $medicine = $this->medicine($doctor, 'Artéméther', 'Artéméther');

        $this->actingAs($doctor)
            ->post('/medicine/protocoles', $this->payload($diagnostic, $medicine, [
                'indications' => ['Fièvre', 'fievre', '  ', 'Frissons'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/medicine/protocoles');

        $protocol = ClinicalProtocol::query()->sole();

        // Un signe en double — même aux accents près — ne compte qu'une fois.
        $this->assertSame(['Fièvre', 'Frissons'], $protocol->indications);
        $this->assertSame('3 fois/jour', $protocol->lines()->sole()->frequency);
        $this->assertTrue(AuditLog::query()->where('action', 'clinical_protocol.create')->exists());
    }

    public function test_an_inverted_age_range_is_refused(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor)
            ->post('/medicine/protocoles', $this->payload(
                $this->diagnostic('B54', 'Paludisme'),
                $this->medicine($doctor, 'Artéméther', 'Artéméther'),
                ['min_age_years' => 14, 'max_age_years' => 1],
            ))
            ->assertSessionHasErrors('max_age_years');
    }

    /** Archiver n'efface rien, et un protocole archivé ne propose plus rien. */
    public function test_an_archived_protocol_stops_proposing_and_can_be_restored(): void
    {
        $doctor = $this->doctor();
        [, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $protocol = $this->protocol($doctor, $this->diagnostic('B54', 'Paludisme'), ['fièvre']);
        $consultation->update(['chief_complaint' => 'fièvre']);

        $this->actingAs($doctor)
            ->post("/medicine/protocoles/{$protocol->uuid}/archive", [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($doctor)
            ->post("/medicine/protocoles/{$protocol->uuid}/archive", ['reason' => 'Remplacé par le protocole national'])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($protocol);
        $this->assertSame([], app(ClinicalProtocolMatcher::class)->suggestDiagnoses($consultation->fresh()));

        $this->actingAs($doctor)->post("/medicine/protocoles/{$protocol->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($protocol);
    }
}
