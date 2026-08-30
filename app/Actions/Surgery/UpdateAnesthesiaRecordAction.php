<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use App\Services\Surgery\AnesthesiaItemNormalizer;
use Illuminate\Validation\ValidationException;

class UpdateAnesthesiaRecordAction
{
    public function __construct(private readonly AnesthesiaItemNormalizer $itemNormalizer) {}

    /**
     * @param  array{consultation_data?: ?array, paraclinical_data?: ?array, anesthetic_items?: ?array, notes?: ?string, administered_at?: ?string}  $data
     */
    public function execute(AnesthesiaRecord $record, array $data): AnesthesiaRecord
    {
        if ($record->validated_at !== null) {
            throw ValidationException::withMessages([
                'anesthesia' => 'Le dossier d’anesthésie validé ne peut plus être modifié.',
            ]);
        }

        if ($record->assessment_validated_at !== null
            && (array_key_exists('consultation_data', $data) || array_key_exists('paraclinical_data', $data))) {
            throw ValidationException::withMessages([
                'assessment' => 'L’évaluation pré-anesthésique validée ne peut plus être modifiée.',
            ]);
        }

        if (array_key_exists('anesthetic_items', $data)) {
            $data['anesthetic_items'] = $this->itemNormalizer->normalize($data['anesthetic_items']);
        }

        $record->fill($data);
        $record->save();

        return $record;
    }
}
