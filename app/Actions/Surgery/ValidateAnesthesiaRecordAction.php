<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ValidateAnesthesiaRecordAction
{
    public function execute(AnesthesiaRecord $record): AnesthesiaRecord
    {
        if ($record->validated_at !== null) {
            throw ValidationException::withMessages([
                'anesthesia' => 'Ce dossier d’anesthésie est déjà validé.',
            ]);
        }

        $record->validated_by = Auth::id();
        $record->validated_at = now();
        $record->save();

        return $record;
    }
}
