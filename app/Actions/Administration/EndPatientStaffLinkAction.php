<?php

namespace App\Actions\Administration;

use App\Models\PatientStaffLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EndPatientStaffLinkAction
{
    public function execute(PatientStaffLink $link, string $reason, User $actor): PatientStaffLink
    {
        if ($actor->cannot('patient_staff_links.end')) {
            throw new AuthorizationException('Vous ne pouvez pas clôturer ce lien personnel.');
        }

        $reason = str($reason)->squish()->toString();

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif de clôture est obligatoire et limité à 1000 caractères.',
            ]);
        }

        return DB::transaction(function () use ($link, $reason, $actor) {
            $link = PatientStaffLink::query()->lockForUpdate()->findOrFail($link->id);

            if (! $link->isActive()) {
                throw ValidationException::withMessages([
                    'staff_link' => 'Ce lien personnel est déjà clôturé.',
                ]);
            }

            $link->fill([
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => $reason,
            ])->save();

            return $link->refresh();
        });
    }
}
