<?php

namespace App\Actions\Medicine;

use App\Enums\ClinicalSuggestionSource;
use App\Enums\DiagnosisType;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\DiagnosticCatalog;
use App\Models\User;
use App\Services\Medicine\ClinicPracticeAdvisor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDiagnosisAction
{
    public function __construct(private readonly ClinicPracticeAdvisor $practice) {}

    public function execute(
        Consultation $consultation,
        DiagnosisType $type,
        ?string $description,
        ?User $actor = null,
        ?string $catalogUuid = null,
        ?string $manualCode = null,
        ?string $notes = null,
        ?string $suggestionProtocolUuid = null,
        ?string $suggestionSource = null,
    ): Diagnosis {
        return DB::transaction(function () use ($consultation, $type, $description, $actor, $catalogUuid, $manualCode, $notes, $suggestionProtocolUuid, $suggestionSource): Diagnosis {
            Consultation::query()->whereKey($consultation->getKey())->lockForUpdate()->firstOrFail();

            $catalog = null;
            if ($catalogUuid) {
                $catalog = DiagnosticCatalog::query()
                    ->where('uuid', $catalogUuid)
                    ->where('is_active', true)
                    ->first();

                if (! $catalog) {
                    throw ValidationException::withMessages([
                        'diagnostic_catalog_uuid' => 'Ce diagnostic du catalogue est introuvable ou désactivé.',
                    ]);
                }

                $alreadyRecorded = Diagnosis::query()
                    ->where('consultation_id', $consultation->getKey())
                    ->where('diagnostic_catalog_id', $catalog->getKey())
                    ->where('type', $type->value)
                    ->whereDoesntHave('cancellation')
                    ->exists();

                if ($alreadyRecorded) {
                    throw ValidationException::withMessages([
                        'diagnostic_catalog_uuid' => 'Ce diagnostic est déjà présent avec le même type dans la consultation.',
                    ]);
                }
            }

            [$source, $protocol] = $this->suggestionOrigin($suggestionSource, $suggestionProtocolUuid, $catalog);

            $manualDescription = trim((string) $description);
            if (! $catalog && $manualDescription === '') {
                throw ValidationException::withMessages([
                    'description' => 'Saisissez le libellé du diagnostic manuel.',
                ]);
            }

            return $consultation->diagnoses()->create([
                'diagnostic_catalog_id' => $catalog?->getKey(),
                'type' => $type,
                'description' => $catalog?->name ?? $manualDescription,
                'catalog_code_snapshot' => $catalog?->code,
                'catalog_name_snapshot' => $catalog?->name,
                'manual_code' => $catalog ? null : $this->nullableTrim($manualCode),
                'notes' => $this->nullableTrim($notes),
                'is_manual' => $catalog === null,
                'recorded_by' => $actor?->getKey() ?? Auth::id(),
                'suggestion_source' => $source,
                'clinical_protocol_id' => $protocol?->getKey(),
            ]);
        });
    }

    /**
     * ADR-111 — d'où venait la proposition retenue, vérifié plutôt que cru.
     *
     * @return array{0: ?ClinicalSuggestionSource, 1: ?ClinicalProtocol}
     */
    private function suggestionOrigin(?string $source, ?string $protocolUuid, ?DiagnosticCatalog $catalog): array
    {
        if ($source === ClinicalSuggestionSource::ClinicPractice->value) {
            // La pratique de la clinique n'a pu proposer que ce qu'elle
            // connaît assez : un diagnostic sans histoire suffisante n'a
            // jamais été proposé, la trace serait fausse.
            if (! $catalog || ! $this->practice->supportsDiagnosis($catalog->getKey())) {
                throw ValidationException::withMessages([
                    'suggestion_source' => 'La pratique de la clinique n’a pas assez de cas pour avoir proposé ce diagnostic.',
                ]);
            }

            return [ClinicalSuggestionSource::ClinicPractice, null];
        }

        $protocol = $this->suggestingProtocol($protocolUuid, $catalog);

        return [$protocol ? ClinicalSuggestionSource::Protocol : null, $protocol];
    }

    /**
     * ADR-111 — la trace d'une proposition n'est acceptée que si elle est
     * vraie : le protocole doit exister et traiter ce diagnostic. Une
     * référence forgée ou périmée est refusée plutôt que d'attribuer au
     * protocole un diagnostic qu'il n'a jamais proposé.
     */
    private function suggestingProtocol(?string $uuid, ?DiagnosticCatalog $catalog): ?ClinicalProtocol
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $protocol = ClinicalProtocol::query()->where('uuid', $uuid)->first();

        if (! $protocol || ! $catalog || $protocol->diagnostic_catalog_id !== $catalog->getKey()) {
            throw ValidationException::withMessages([
                'suggestion_protocol_uuid' => 'Cette proposition ne correspond à aucun protocole de ce diagnostic.',
            ]);
        }

        return $protocol;
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
