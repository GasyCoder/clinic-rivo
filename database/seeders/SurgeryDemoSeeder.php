<?php

namespace Database\Seeders;

use App\Enums\SurgicalCarePhase;
use App\Enums\SurgicalTeamFunction;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fake data for manually exercising the Chirurgie UI end to end — NOT part
 * of DatabaseSeeder::run(), never seeded automatically. Run explicitly:
 *
 *   php artisan db:seed --class=SurgeryDemoSeeder
 *
 * Creates its own demo patients/episodes/users (via the existing Patient/
 * Episode/User models — this only uses those modules, it does not modify
 * them) so every SurgicalRequest status and every sub-resource has at least
 * one populated example to look at.
 */
class SurgeryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $surgeonRole = Role::query()->where('code', 'SURGERY')->firstOrFail();
        $nurseRole = Role::query()->where('code', 'NURSE')->first();

        $surgeon = User::query()->updateOrCreate(
            ['email' => 'demo.surgeon@rivo.mg'],
            ['name' => 'Dr. Andry Rakoto', 'password' => 'password', 'role_id' => $surgeonRole->id],
        );
        $secondSurgeon = User::query()->updateOrCreate(
            ['email' => 'demo.surgeon2@rivo.mg'],
            ['name' => 'Dr. Fanja Rasoanaivo', 'password' => 'password', 'role_id' => $surgeonRole->id],
        );
        $anesthetist = User::query()->updateOrCreate(
            ['email' => 'demo.nurse@rivo.mg'],
            ['name' => 'Nirina Rabe', 'password' => 'password', 'role_id' => $nurseRole->id ?? $surgeonRole->id],
        );
        $orNurse = User::query()->updateOrCreate(
            ['email' => 'demo.nurse2@rivo.mg'],
            ['name' => 'Hery Andrianina', 'password' => 'password', 'role_id' => $nurseRole->id ?? $surgeonRole->id],
        );

        $episodes = collect(['PENDING', 'SCHEDULED', 'PREOPERATIVE_VALIDATED', 'IN_PROGRESS', 'COMPLETED', 'DISCHARGED'])
            ->mapWithKeys(fn ($status, $index) => [$status => $this->makeEpisode($index)]);

        $this->makePending($episodes['PENDING']);
        $this->makeScheduled($episodes['SCHEDULED'], $secondSurgeon, $orNurse);
        $this->makePreoperativeValidated($episodes['PREOPERATIVE_VALIDATED'], $surgeon, $anesthetist);
        $this->makeInProgress($episodes['IN_PROGRESS'], $surgeon, $anesthetist, $orNurse);
        $this->makeCompleted($episodes['COMPLETED'], $surgeon, $anesthetist, $orNurse);
        $this->makeDischarged($episodes['DISCHARGED'], $secondSurgeon, $anesthetist, $orNurse);
    }

    private function makeEpisode(int $index): Episode
    {
        $number = str_pad((string) (900 + $index), 6, '0', STR_PAD_LEFT);

        $patient = Patient::query()->updateOrCreate(
            ['patient_number' => "DEMO-{$number}"],
            [
                'first_name' => ['Voahangy', 'Tiana', 'Mamy', 'Fetra', 'Njaka', 'Soa'][$index] ?? 'Patient',
                'last_name' => 'Démo Chirurgie',
                'birth_date' => now()->subYears(25 + $index)->toDateString(),
                'sex' => $index % 2 === 0 ? 'F' : 'M',
            ],
        );

        return Episode::query()->updateOrCreate(
            ['episode_number' => "DEMO-EP-{$number}"],
            [
                'patient_id' => $patient->id,
                'status' => 'OPEN',
                'administrative_status' => 'IN_CARE',
                'started_at' => now()->subDays(6 - $index),
            ],
        );
    }

    private function makePending(Episode $episode): void
    {
        $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Appendicectomie'],
            ['status' => 'PENDING', 'notes' => 'Douleur fosse iliaque droite depuis 48h.'],
        );
    }

    private function makeScheduled(Episode $episode, User $surgeon, User $orNurse): void
    {
        $request = $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Cholécystectomie'],
            [
                'status' => 'SCHEDULED',
                'notes' => 'Lithiase vésiculaire symptomatique.',
                'surgeon_id' => $surgeon->id,
                'scheduled_at' => now()->addDays(2),
                'operating_room' => 'Bloc 1',
                'preparation_notes' => 'Plateau de cœlioscopie vérifié.',
            ],
        );

        $request->teamMembers()->updateOrCreate(
            ['user_id' => $surgeon->id, 'function' => SurgicalTeamFunction::Surgeon->value],
            ['assigned_by' => $surgeon->id, 'assigned_at' => now()],
        );
        $request->teamMembers()->updateOrCreate(
            ['user_id' => $orNurse->id, 'function' => SurgicalTeamFunction::OrNurse->value],
            ['assigned_by' => $surgeon->id, 'assigned_at' => now()],
        );
    }

    private function makePreoperativeValidated(Episode $episode, User $surgeon, User $anesthetist): void
    {
        $request = $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Cure de hernie inguinale'],
            [
                'status' => 'PREOPERATIVE_VALIDATED',
                'surgeon_id' => $surgeon->id,
                'scheduled_at' => now()->addDay(),
                'operating_room' => 'Bloc 2',
                'preoperative_notes' => 'ASA I, à jeun depuis 8h, bilan pré-op normal.',
                'preoperative_assessed_by' => $anesthetist->id,
                'preoperative_assessed_at' => now()->subHours(3),
                'preoperative_validated_by' => $surgeon->id,
                'preoperative_validated_at' => now()->subHour(),
            ],
        );

        $request->anesthesiaRecord()->updateOrCreate([], [
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'Anesthésie générale prévue.',
        ]);
    }

    private function makeInProgress(Episode $episode, User $surgeon, User $anesthetist, User $orNurse): void
    {
        $request = $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Césarienne'],
            [
                'status' => 'IN_PROGRESS',
                'surgeon_id' => $surgeon->id,
                'scheduled_at' => now()->subHour(),
                'operating_room' => 'Bloc Maternité',
                'preoperative_notes' => 'Terme + travail stationnaire.',
                'preoperative_assessed_by' => $anesthetist->id,
                'preoperative_assessed_at' => now()->subHours(2),
                'preoperative_validated_by' => $surgeon->id,
                'preoperative_validated_at' => now()->subHours(2),
            ],
        );

        $request->teamMembers()->updateOrCreate(
            ['user_id' => $orNurse->id, 'function' => SurgicalTeamFunction::OrNurse->value],
            ['assigned_by' => $surgeon->id, 'assigned_at' => now()->subHour()],
        );

        $request->anesthesiaRecord()->updateOrCreate([], [
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'Rachianesthésie.',
            'administered_at' => now()->subMinutes(45),
            'validated_by' => $anesthetist->id,
            'validated_at' => now()->subMinutes(40),
        ]);

        $request->intervention()->updateOrCreate([], [
            'performed_by' => $surgeon->id,
            'started_at' => now()->subMinutes(30),
        ]);

        $request->consumables()->updateOrCreate(
            ['label' => 'Champs stériles'],
            ['quantity' => 4, 'unit' => 'unités', 'recorded_by' => $orNurse->id],
        );
        $request->consumables()->updateOrCreate(
            ['label' => 'Fil de suture Vicryl 2/0'],
            ['quantity' => 3, 'unit' => 'unités', 'recorded_by' => $orNurse->id],
        );
    }

    private function makeCompleted(Episode $episode, User $surgeon, User $anesthetist, User $orNurse): void
    {
        $request = $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Réduction de fracture fermée'],
            [
                'status' => 'IN_PROGRESS',
                'surgeon_id' => $surgeon->id,
                'scheduled_at' => now()->subHours(6),
                'operating_room' => 'Bloc 1',
                'preoperative_notes' => 'ASA I.',
                'preoperative_assessed_by' => $anesthetist->id,
                'preoperative_assessed_at' => now()->subHours(7),
                'preoperative_validated_by' => $surgeon->id,
                'preoperative_validated_at' => now()->subHours(7),
            ],
        );

        $request->anesthesiaRecord()->updateOrCreate([], [
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'Anesthésie locorégionale.',
            'administered_at' => now()->subHours(6),
            'validated_by' => $anesthetist->id,
            'validated_at' => now()->subHours(6),
        ]);

        $request->intervention()->updateOrCreate([], [
            'performed_by' => $surgeon->id,
            'started_at' => now()->subHours(6),
            'ended_at' => now()->subHours(5),
            'procedure_summary' => 'Réduction orthopédique sans complication.',
        ]);

        $request->complications()->updateOrCreate(
            ['description' => 'Saignement mineur maîtrisé par compression.'],
            ['reported_by' => $surgeon->id, 'reported_at' => now()->subHours(5)],
        );

        $request->careNotes()->updateOrCreate(
            ['phase' => SurgicalCarePhase::Postoperative->value, 'note' => 'Surveillance neurovasculaire normale à 2h.'],
            ['recorded_by' => $orNurse->id, 'recorded_at' => now()->subHours(3)],
        );

        $report = $request->report()->updateOrCreate([], [
            'authored_by' => $surgeon->id,
            'content' => "Réduction fermée réalisée sous anesthésie locorégionale. Contrôle radiologique satisfaisant. Immobilisation plâtrée mise en place. Aucune complication notable hormis saignement mineur maîtrisé.",
        ]);

        if (! $report->validated_at) {
            $report->validated_by = $surgeon->id;
            $report->validated_at = now()->subHours(4);
            $report->saveQuietly();
        }

        $request->status = 'COMPLETED';
        $request->completed_at = now()->subHours(4);
        $request->saveQuietly();
    }

    private function makeDischarged(Episode $episode, User $surgeon, User $anesthetist, User $orNurse): void
    {
        $request = $episode->surgicalRequests()->updateOrCreate(
            ['procedure_name' => 'Amygdalectomie'],
            [
                'status' => 'IN_PROGRESS',
                'surgeon_id' => $surgeon->id,
                'scheduled_at' => now()->subDay(),
                'operating_room' => 'Bloc 2',
                'preoperative_notes' => 'ASA I, enfant.',
                'preoperative_assessed_by' => $anesthetist->id,
                'preoperative_assessed_at' => now()->subDay(),
                'preoperative_validated_by' => $surgeon->id,
                'preoperative_validated_at' => now()->subDay(),
            ],
        );

        $request->anesthesiaRecord()->updateOrCreate([], [
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'Anesthésie générale, intubation simple.',
            'administered_at' => now()->subDay(),
            'validated_by' => $anesthetist->id,
            'validated_at' => now()->subDay(),
        ]);

        $request->intervention()->updateOrCreate([], [
            'performed_by' => $surgeon->id,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(40),
            'procedure_summary' => 'Amygdalectomie bilatérale sans incident.',
        ]);

        $request->careNotes()->updateOrCreate(
            ['phase' => SurgicalCarePhase::Postoperative->value, 'note' => 'Reprise alimentaire progressive bien tolérée.'],
            ['recorded_by' => $orNurse->id, 'recorded_at' => now()->subHours(20)],
        );

        $report = $request->report()->updateOrCreate([], [
            'authored_by' => $surgeon->id,
            'content' => 'Amygdalectomie bilatérale réalisée sans complication. Suites simples.',
        ]);

        if (! $report->validated_at) {
            $report->validated_by = $surgeon->id;
            $report->validated_at = now()->subHours(22);
            $report->saveQuietly();
        }

        $request->status = 'DISCHARGED';
        $request->completed_at = now()->subHours(22);
        $request->discharged_by = $surgeon->id;
        $request->discharged_at = now()->subHours(18);
        $request->discharge_notes = 'Sortie à domicile, consignes alimentaires données.';
        $request->saveQuietly();
    }
}
