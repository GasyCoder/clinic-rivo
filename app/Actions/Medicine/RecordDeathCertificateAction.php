<?php

namespace App\Actions\Medicine;

use App\Enums\MedicalDischargeType;
use App\Models\DeathRecord;
use App\Models\Episode;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Établir l'acte de constatation d'un décès (ADR-107).
 *
 * L'acte ne prononce pas le décès : c'est la sortie médicale qui l'a fait
 * (ADR-035). Il ne peut donc exister que pour un passage dont la sortie
 * porte le type `DECEASED`, et ne peut pas le devancer — signer la
 * constatation d'un décès que personne n'a prononcé attesterait un fait
 * clinique inexistant.
 */
class RecordDeathCertificateAction
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @param array<string, mixed> $data */
    public function execute(Episode $episode, array $data, User $actor): DeathRecord
    {
        return DB::transaction(function () use ($episode, $data, $actor): DeathRecord {
            /** @var Episode $locked */
            $locked = Episode::query()
                ->with(['medicalDischarge', 'deathRecord'])
                ->lockForUpdate()
                ->findOrFail($episode->getKey());

            $discharge = $locked->medicalDischarge;

            if (! $discharge || $discharge->type !== MedicalDischargeType::Deceased) {
                throw ValidationException::withMessages([
                    'death_record' => 'Ce passage ne porte aucune sortie médicale de type Décès.',
                ]);
            }

            // Un second acte serait un doublon d'état civil, jamais une
            // correction : celle-ci relève d'un mécanisme tracé (ADR-010).
            if ($locked->deathRecord) {
                throw ValidationException::withMessages([
                    'death_record' => 'L’acte de constatation de ce passage est déjà établi.',
                ]);
            }

            $clean = static fn (mixed $value): ?string => trim((string) $value) ?: null;
            // Un éditeur vidé renvoie des balises sans texte : c'est une
            // absence, pas un contenu.
            $rich = fn (mixed $value): ?string => $this->richText->isBlank((string) $value)
                ? null
                : $this->richText->sanitize((string) $value);

            if ($rich($data['death_causes'] ?? null) === null) {
                throw ValidationException::withMessages(['death_causes' => 'Indiquez les causes constatées.']);
            }

            return DeathRecord::query()->create([
                'episode_id' => $locked->getKey(),
                'patient_id' => $locked->patient_id,
                'medical_discharge_id' => $discharge->getKey(),
                // Le défunt tel que la feuille de la clinique le décrit, figé
                // au jour de l'acte : corriger ensuite le dossier ne réécrit
                // pas un certificat déjà remis à la famille.
                'birth_place' => $clean($data['birth_place'] ?? null),
                'address' => $clean($data['address'] ?? null),
                'father_name' => $clean($data['father_name'] ?? null),
                'mother_name' => $clean($data['mother_name'] ?? null),
                'identity_document_number' => $clean($data['identity_document_number'] ?? null),
                'identity_document_issued_on' => $data['identity_document_issued_on'] ?? null,
                'identity_document_issued_place' => $clean($data['identity_document_issued_place'] ?? null),
                // « Signatures — Lieu » : le site, sauf si le médecin signe
                // ailleurs.
                'signed_place' => $clean($data['signed_place'] ?? null) ?? config('rivo.site.name'),
                // Préremplis depuis la sortie médicale, corrigeables ici :
                // le médecin qui constate signe ce qu'il écrit, il ne
                // contresigne pas la saisie d'un autre écran.
                'death_occurred_at' => $data['death_occurred_at'],
                'death_place' => trim((string) $data['death_place']),
                'death_causes' => $rich($data['death_causes']),
                'observations' => $rich($data['observations'] ?? null),
                // L'heure de la constatation appartient au serveur : elle
                // atteste quand l'acte a été signé, pas quand on a rempli le
                // formulaire (même règle que la date de demande, ADR-069).
                'constated_at' => now(),
                'constated_by' => $actor->getKey(),
            ]);
        });
    }
}
