<?php

namespace App\Actions\Laboratory;

use App\Models\LabResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-213 — signaler (ou retirer) un résultat critique. Le catalogue ne porte
 * aucun seuil critique : c'est un jugement du laboratoire, jamais un calcul.
 * Possible même après la validation — une alerte n'est pas une correction —
 * et toujours tracé.
 */
class FlagCriticalLabResultAction
{
    public function execute(LabResult $result, bool $critical, User $actor): LabResult
    {
        if ($actor->cannot('laboratory_results.flag_critical')) {
            throw new AuthorizationException('Signaler un résultat critique demande le droit « laboratory_results.flag_critical ».');
        }

        return DB::transaction(function () use ($result, $critical, $actor): LabResult {
            LabItemGuard::lock($result->item);

            $result->update([
                'is_critical' => $critical,
                'critical_flagged_at' => $critical ? now() : null,
                'critical_flagged_by' => $critical ? $actor->getKey() : null,
            ]);

            return $result->fresh();
        });
    }
}
