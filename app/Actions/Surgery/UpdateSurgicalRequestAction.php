<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class UpdateSurgicalRequestAction
{
    /**
     * Covers surgery.update — general case fields plus recording the
     * preoperative assessment itself (CDC GitHub §15/16 has no dedicated
     * surgery.preoperative.create/update permission; only .view/.validate
     * are separate, see SurgicalRequest's doc comment).
     *
     * @param  array{procedure_name?: string, notes?: ?string, preoperative_notes?: ?string}  $data
     */
    public function execute(SurgicalRequest $surgicalRequest, array $data): SurgicalRequest
    {
        if (array_key_exists('procedure_name', $data)) {
            $surgicalRequest->procedure_name = $data['procedure_name'];
        }

        if (array_key_exists('notes', $data)) {
            $surgicalRequest->notes = $data['notes'];
        }

        if (array_key_exists('preoperative_notes', $data)) {
            $surgicalRequest->preoperative_notes = $data['preoperative_notes'];
            $surgicalRequest->preoperative_assessed_by = Auth::id();
            $surgicalRequest->preoperative_assessed_at = now();
        }

        $surgicalRequest->save();

        return $surgicalRequest;
    }
}
