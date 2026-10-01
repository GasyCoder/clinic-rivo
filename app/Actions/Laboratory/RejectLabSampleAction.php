<?php

namespace App\Actions\Laboratory;

use App\Models\LabSample;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — déclarer un prélèvement non conforme (hémolysé, coagulé,
 * insuffisant, mal identifié…). Il n'est jamais effacé : il garde son motif,
 * son auteur et l'heure, et le laboratoire en enregistre un autre.
 */
class RejectLabSampleAction
{
    public function execute(LabSample $sample, string $reason, User $actor): LabSample
    {
        if ($actor->cannot('laboratory_samples.update')) {
            throw new AuthorizationException('Déclarer un prélèvement non conforme demande le droit « laboratory_samples.update ».');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi le prélèvement n’est pas conforme.']);
        }

        return DB::transaction(function () use ($sample, $reason, $actor): LabSample {
            $locked = LabSample::query()->lockForUpdate()->findOrFail($sample->getKey());

            if ($locked->rejected_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Ce prélèvement est déjà déclaré non conforme.']);
            }
            if ($locked->labRequest()->value('cancelled_at') !== null) {
                throw ValidationException::withMessages(['reason' => 'Cette demande a été retirée par le prescripteur.']);
            }

            $locked->update([
                'rejected_at' => now(),
                'rejected_by' => $actor->getKey(),
                'rejection_reason' => mb_substr($reason, 0, 500),
            ]);

            return $locked->fresh();
        });
    }
}
