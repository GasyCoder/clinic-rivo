<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationOrientationType;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\MedicalDischarge;
use App\Models\User;
use App\Support\Medicine\MedicalDischargeAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMedicalDischargeAction
{
    public function __construct(
        private readonly RecordConsultationOrientationAction $recordOrientation,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MedicalDischarge
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MedicalDischarge {
            $locked = EpisodeOrientation::query()
                ->with(['episode.medicalDischarge', 'consultation'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine
                || $locked->status !== EpisodeOrientationStatus::InProgress
                || ! $locked->consultation) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'La sortie exige une consultation Médecine active.',
                ]);
            }

            if ($locked->episode->medicalDischarge) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Une sortie médicale a déjà été prononcée pour ce passage.',
                ]);
            }

            // ADR-162 (amende l'emplacement posé par l'ADR-156) — il n'y a
            // qu'une sortie médicale, et pour un patient au lit elle se
            // prononce sur la page du séjour, qui seule termine le séjour et
            // la prise en charge ensemble. Règle de cohérence du dossier, pas
            // de droit : elle vaut pour tout compte (ADR-152).
            $hospitalized = HospitalStay::query()
                ->where('episode_id', $locked->episode_id)
                ->where('status', HospitalStayStatus::Active->value)
                ->exists();

            if ($hospitalized) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Ce patient est hospitalisé : sa sortie se prononce sur la page du séjour (Hospitalisation).',
                ]);
            }

            $type = MedicalDischargeType::from($data['type']);
            $this->recordNewDiagnoses($locked, (string) ($data['final_diagnosis'] ?? ''), $actor);

            $discharge = MedicalDischarge::query()->create([
                ...MedicalDischargeAttributes::from($data, $type),
                'episode_id' => $locked->episode_id,
                'consultation_id' => $locked->consultation->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            // Recording the discharge no longer ends the encounter (ADR-084).
            // The Médecine orientation and the episode statuses move at the
            // Clôture step, so a doctor who pronounces a discharge can still
            // prescribe, print and check their file before closing — which
            // is exactly what the old "Décision"-last pathway prevented.
            $this->recordOrientation->submit(
                $locked->consultation,
                $type === MedicalDischargeType::Transfer
                    ? ConsultationOrientationType::Referral
                    : ConsultationOrientationType::Discharge,
                ['medical_discharge_id' => $discharge->getKey()],
                null,
                $actor,
            );

            return $discharge->fresh(['creator:id,name']);
        });
    }

    /**
     * Le diagnostic final se lit ligne par ligne : il est composé des
     * diagnostics déjà consignés, un par ligne. Comparer le texte entier au
     * dernier diagnostic enregistrait la liste comme un diagnostic de plus
     * (le défaut corrigé sur le séjour par l'ADR-162). Seule une ligne que la
     * consultation ne connaît pas encore devient un diagnostic.
     */
    private function recordNewDiagnoses(EpisodeOrientation $orientation, string $finalDiagnosis, User $actor): void
    {
        $lines = collect(preg_split('/\R/u', $finalDiagnosis))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique(fn (string $line): string => mb_strtolower($line));

        if ($lines->isEmpty()) {
            return;
        }

        $known = $orientation->consultation->diagnoses()
            ->whereDoesntHave('cancellation')
            ->pluck('description')
            ->map(fn (string $description): string => mb_strtolower(trim($description)))
            ->all();

        foreach ($lines as $line) {
            if (in_array(mb_strtolower($line), $known, true)) {
                continue;
            }

            Diagnosis::query()->create([
                'consultation_id' => $orientation->consultation->getKey(),
                'type' => DiagnosisType::Final,
                'description' => $line,
                'recorded_by' => $actor->getKey(),
            ]);
        }
    }
}
