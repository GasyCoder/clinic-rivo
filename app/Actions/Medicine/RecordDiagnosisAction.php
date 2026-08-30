<?php

namespace App\Actions\Medicine;

use App\Enums\DiagnosisType;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\DiagnosticCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDiagnosisAction
{
    public function execute(
        Consultation $consultation,
        DiagnosisType $type,
        ?string $description,
        ?User $actor = null,
        ?string $catalogUuid = null,
        ?string $manualCode = null,
        ?string $notes = null,
    ): Diagnosis {
        return DB::transaction(function () use ($consultation, $type, $description, $actor, $catalogUuid, $manualCode, $notes): Diagnosis {
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
            ]);
        });
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
