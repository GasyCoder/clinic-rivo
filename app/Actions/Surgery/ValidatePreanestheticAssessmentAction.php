<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidatePreanestheticAssessmentAction
{
    public function execute(AnesthesiaRecord $record): AnesthesiaRecord
    {
        return DB::transaction(function () use ($record): AnesthesiaRecord {
            $locked = AnesthesiaRecord::query()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->assessment_validated_at !== null) {
                throw ValidationException::withMessages([
                    'assessment' => 'Cette évaluation pré-anesthésique est déjà validée.',
                ]);
            }

            if (! $this->hasMeaningfulData($locked->consultation_data)
                || ! $this->hasMeaningfulData($locked->paraclinical_data)) {
                throw ValidationException::withMessages([
                    'assessment' => 'Renseignez la consultation et l’examen paraclinique avant la validation.',
                ]);
            }

            $locked->assessment_validated_by = Auth::id();
            $locked->assessment_validated_at = now();
            $locked->save();

            return $locked;
        });
    }

    private function hasMeaningfulData(mixed $value): bool
    {
        if (! is_array($value)) {
            return $value !== null && $value !== '';
        }

        foreach ($value as $item) {
            if ($this->hasMeaningfulData($item)) {
                return true;
            }
        }

        return false;
    }
}
