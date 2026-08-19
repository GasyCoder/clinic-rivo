<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;

class UpdateAnesthesiaRecordAction
{
    /**
     * @param  array{notes?: ?string, administered_at?: ?string}  $data
     */
    public function execute(AnesthesiaRecord $record, array $data): AnesthesiaRecord
    {
        $record->fill($data);
        $record->save();

        return $record;
    }
}
