<?php

namespace App\Services\Surgery;

use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class SurgicalCaseWorkspace
{
    public function loadForSurgery(SurgicalRequest $surgicalRequest, bool $includeAnesthesia): SurgicalRequest
    {
        $relations = [
            'episode.patient.addressEntry',
            'catalogItem:id,uuid,code,name',
            'requestedBy',
            'surgeon',
            'preoperativeAssessedBy',
            'preoperativeValidatedBy',
            'dischargedBy',
            'intervention.performedBy',
            'report.author',
            'report.validator',
            'complications.reportedBy',
            'consumables.recordedBy',
            'teamMembers.user',
            'teamMembers.assignedBy',
            'careNotes.recordedBy',
            'blockEntry',
            'blockExit',
            'observations',
            'treatmentItems',
        ];

        if ($includeAnesthesia) {
            array_push(
                $relations,
                'anesthesiaRecord.anesthetist',
                'anesthesiaRecord.validator',
                'anesthesiaRecord.assessmentValidator',
            );
        } else {
            // Frontend checks are only UX. Do not serialize anesthetic data to
            // an account whose explicit anesthesia.view access was denied.
            $surgicalRequest->unsetRelation('anesthesiaRecord');
        }

        return $surgicalRequest->load($relations);
    }

    public function loadForAnesthesia(SurgicalRequest $surgicalRequest): SurgicalRequest
    {
        // Anesthesia shares the case identity and patient context required for
        // safe assessment, not the whole surgical report/consumables/follow-up
        // payload. Module separation is enforced before serialization.
        return $surgicalRequest
            ->makeHidden([
                'requested_by',
                'created_by',
                'preparation_notes',
                'preoperative_notes',
                'preoperative_assessed_by',
                'preoperative_assessed_at',
                'preoperative_validated_by',
                'preoperative_validated_at',
                'completed_at',
                'discharged_by',
                'discharged_at',
                'discharge_notes',
            ])
            ->load([
                'episode.patient.addressEntry',
                'catalogItem:id,uuid,code,name',
                'surgeon',
                'anesthesiaRecord.anesthetist',
                'anesthesiaRecord.validator',
                'anesthesiaRecord.assessmentValidator',
            ]);
    }

    /** @return Collection<int, User> */
    public function activeUsers(): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('deactivated_at')
            ->with(['role:id,code,name', 'professionalProfile:id,code,name'])
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'role_id', 'professional_profile_id']);
    }
}
