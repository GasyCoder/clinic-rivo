<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use Illuminate\Support\Facades\Auth;

class ValidateAnesthesiaRecordAction
{
    public function execute(AnesthesiaRecord $record): AnesthesiaRecord
    {
        $record->validated_by = Auth::id();
        $record->validated_at = now();
        $record->save();

        return $record;
    }
}
