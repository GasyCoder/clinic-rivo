<?php

namespace Tests\Feature\Medicine;

use App\Enums\ClinicalSuggestionSource;
use App\Models\Consultation;
use App\Models\DiagnosticCatalog;
use App\Models\EpisodeOrientation;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Medicine\ClinicalProtocolMatcher;
use App\Services\Medicine\ClinicPracticeAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Medicine\Concerns\BuildsClinicalSuggestionFixtures;
use Tests\TestCase;

/**
 * ADR-111 — l'algorithme apprend de la pratique de la clinique.
 *
 * Il ne lit que la base du site. Ce que ces tests protègent : il ne propose
 * qu'à partir de cas réels et assez nombreux, il dit sur combien il s'appuie,
 * il ne se sert pas de la consultation en cours comme preuve d'elle-même, il
 * signale un patient hors de la tranche d'âge déjà traitée, et il cède la
 * place au protocole quand la clinique en a écrit un.
 */
class ClinicPracticeSuggestionTest extends TestCase
{
    use BuildsClinicalSuggestionFixtures, RefreshDatabase;

    public function test_a_diagnosis_is_learned_from_past_consultations(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');

        foreach (['Toux grasse et fièvre', 'Toux productive depuis 4 jours', 'Toux, expectorations, fièvre'] as $complaint) {
            $this->pastCase($doctor, $bronchitis, $complaint);
        }

        [, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $consultation->update(['chief_complaint' => 'Toux depuis hier soir']);

        $suggestions = $this->advisor()->suggestDiagnoses($consultation->fresh(), $this->contextOf($consultation), []);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Bronchite aiguë', $suggestions[0]['name']);
        $this->assertSame('CLINIC_PRACTICE', $suggestions[0]['source']);
        $this->assertSame(3, $suggestions[0]['cases']);
        // La preuve : le mot retrouvé, et dans combien de consultations.
        $this->assertSame([['term' => 'toux', 'count' => 3]], $suggestions[0]['matched_terms']);
    }

    /** Deux consultations sont une anecdote, pas une pratique. */
    public function test_nothing_is_learned_below_the_minimum_number_of_cases(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $this->pastCase($doctor, $bronchitis, 'Toux grasse');
        $this->pastCase($doctor, $bronchitis, 'Toux sèche');

        [, $consultation] = $this->consultation($doctor, birthDate: '1990-01-01');
        $consultation->update(['chief_complaint' => 'Toux']);

        $this->assertSame([], $this->advisor()->suggestDiagnoses($consultation->fresh(), $this->contextOf($consultation), []));
    }

    public function test_the_usual_prescription_is_learned_with_its_most_frequent_posology(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');

        $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin, duration: '7 jours');
        $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin, duration: '7 jours');
        $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin, duration: '10 jours');

        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1985-01-01');
        $this->recordDiagnosis($doctor, $orientation, $bronchitis);

        $groups = $this->advisor()->suggestPrescription($consultation->fresh(), $this->contextOf($consultation), [$bronchitis->id]);

        $line = $groups[0]['lines'][0];
        $this->assertSame('CLINIC_PRACTICE', $groups[0]['source']);
        $this->assertSame($amoxicillin->catalogItem->uuid, $line['medicine_uuid']);
        // La posologie qui revient le plus souvent, pas la dernière saisie.
        $this->assertSame('7 jours', $line['duration']);
        $this->assertSame(['count' => 3, 'cases' => 3], $line['support']);
        // La quantité est laissée au calcul de la posologie (ADR-110).
        $this->assertNull($line['quantity']);
        $this->assertNull($line['age_note']);
    }

    /** Une dose d'adulte ne se transpose pas à un enfant. */
    public function test_a_patient_outside_the_age_range_already_treated_is_flagged(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');

        foreach (['1980-01-01', '1985-01-01', '1990-01-01'] as $birthDate) {
            $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin, birthDate: $birthDate);
        }

        [$orientation, $consultation] = $this->consultation($doctor, birthDate: now()->subYears(5)->toDateString());
        $this->recordDiagnosis($doctor, $orientation, $bronchitis);

        $line = $this->advisor()->suggestPrescription($consultation->fresh(), $this->contextOf($consultation), [$bronchitis->id])[0]['lines'][0];

        $this->assertStringContainsString('ce patient a 5 ans', $line['age_note']);
    }

    /** La consultation en cours ne se sert pas de preuve à elle-même. */
    public function test_the_current_consultation_does_not_count_as_its_own_evidence(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');

        $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin);
        $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin);

        // La troisième consultation est celle en cours : avec elle on
        // atteindrait le minimum, sans elle non.
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1985-01-01');
        $this->recordDiagnosis($doctor, $orientation, $bronchitis);
        $this->prescribe($doctor, $orientation, $amoxicillin);

        $this->assertSame([], $this->advisor()->suggestPrescription($consultation->fresh(), $this->contextOf($consultation), [$bronchitis->id]));
    }

    /** Le protocole écrit par la clinique passe avant la pratique observée. */
    public function test_an_applicable_protocol_takes_precedence_over_the_practice(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $paracetamol = $this->medicine($doctor, 'Paracétamol 500 mg', 'Paracétamol');

        foreach (range(1, 3) as $_) {
            $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin);
        }

        $this->protocol($doctor, $bronchitis, [], lines: [[
            'medicine_uuid' => $paracetamol->catalogItem->uuid, 'dosage' => '1 g', 'frequency' => '3 fois/jour',
        ]]);

        [$orientation] = $this->consultation($doctor, birthDate: '1985-01-01');
        $this->recordDiagnosis($doctor, $orientation, $bronchitis);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clinical_suggestions.prescription.groups', 1)
                ->where('clinical_suggestions.prescription.groups.0.source', 'PROTOCOL')
                // Aucun identifiant SQL ne quitte le serveur (ADR-005).
                ->missing('clinical_suggestions.prescription.groups.0.diagnostic_catalog_id')
            );
    }

    public function test_retaining_a_learned_diagnosis_is_traced_and_verified(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $flu = $this->diagnostic('J11', 'Grippe');

        foreach (range(1, 3) as $_) {
            $this->pastCase($doctor, $bronchitis, 'Toux grasse');
        }

        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1985-01-01');

        // La grippe n'a aucune histoire : la pratique n'a pas pu la proposer.
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
                'type' => 'FINAL',
                'diagnostic_catalog_uuid' => $flu->uuid,
                'suggestion_source' => 'CLINIC_PRACTICE',
            ])
            ->assertSessionHasErrors('suggestion_source');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
                'type' => 'FINAL',
                'diagnostic_catalog_uuid' => $bronchitis->uuid,
                'suggestion_source' => 'CLINIC_PRACTICE',
            ])
            ->assertSessionHasNoErrors();

        $diagnosis = $consultation->diagnoses()->sole();
        $this->assertSame(ClinicalSuggestionSource::ClinicPractice, $diagnosis->suggestion_source);
        $this->assertNull($diagnosis->clinical_protocol_id);
    }

    public function test_a_learned_prescription_line_is_verified_against_the_practice(): void
    {
        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');
        $amoxicillin = $this->medicine($doctor, 'Amoxicilline 500 mg', 'Amoxicilline');
        $paracetamol = $this->medicine($doctor, 'Paracétamol 500 mg', 'Paracétamol');

        foreach (range(1, 3) as $_) {
            $this->pastCase($doctor, $bronchitis, 'Toux', $amoxicillin);
        }

        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1985-01-01');
        $this->recordDiagnosis($doctor, $orientation, $bronchitis);

        // Jamais prescrit pour une bronchite dans cette clinique.
        $this->prescribe($doctor, $orientation, $paracetamol, ['suggestion_source' => 'CLINIC_PRACTICE'])
            ->assertSessionHasErrors('lines.0.suggestion_source');

        $this->prescribe($doctor, $orientation, $amoxicillin, ['suggestion_source' => 'CLINIC_PRACTICE'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ClinicalSuggestionSource::ClinicPractice,
            $consultation->prescriptions()->sole()->lines()->sole()->suggestion_source,
        );
    }

    /** Local, et seulement local : aucune requête ne quitte le serveur. */
    public function test_suggestions_never_call_an_external_service(): void
    {
        // Le rendu serveur d'Inertia appelle un processus de la même
        // machine : hors sujet ici. Tout le reste est interdit.
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();

        $doctor = $this->doctor();
        $bronchitis = $this->diagnostic('J20', 'Bronchite aiguë');

        foreach (range(1, 3) as $_) {
            $this->pastCase($doctor, $bronchitis, 'Toux grasse');
        }

        [$orientation, $consultation] = $this->consultation($doctor, birthDate: '1985-01-01');
        $consultation->update(['chief_complaint' => 'Toux']);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('clinical_suggestions.diagnoses.0.source', 'CLINIC_PRACTICE'));
    }

    // --- fixtures -------------------------------------------------------

    private function advisor(): ClinicPracticeAdvisor
    {
        return app(ClinicPracticeAdvisor::class);
    }

    private function contextOf(Consultation $consultation): array
    {
        return app(ClinicalProtocolMatcher::class)->context($consultation->fresh());
    }

    /** Une consultation conclue : motif, diagnostic, et éventuellement une ordonnance. */
    private function pastCase(
        User $doctor,
        DiagnosticCatalog $diagnostic,
        string $complaint,
        ?Medicine $medicine = null,
        string $birthDate = '1985-01-01',
        string $duration = '7 jours',
    ): void {
        [$orientation, $consultation] = $this->consultation($doctor, birthDate: $birthDate);
        $consultation->update(['chief_complaint' => $complaint]);
        $this->recordDiagnosis($doctor, $orientation, $diagnostic);

        if ($medicine) {
            $this->prescribe($doctor, $orientation, $medicine, ['duration' => $duration])->assertSessionHasNoErrors();
        }
    }

    private function prescribe(User $doctor, EpisodeOrientation $orientation, Medicine $medicine, array $overrides = [])
    {
        return $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [array_merge([
                'manual' => false,
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 21,
                'dosage' => '500 mg',
                'frequency' => '3 fois/jour',
                'duration' => '7 jours',
            ], $overrides)],
        ]);
    }
}
