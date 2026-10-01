<?php

namespace App\Actions\Laboratory;

use App\Models\LabResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-213 — signaler (ou retirer) un résultat critique : un jugement du
 * laboratoire. Possible même après la validation — une alerte n'est pas une
 * correction — et toujours tracé.
 *
 * ADR-214 — une marque posée d'office par une borne critique du catalogue se
 * retire ici aussi ; elle reste retirée tant que la valeur ne change pas.
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

            $source = $critical
                ? LabResult::CRITICAL_MANUAL
                : ($result->critical_source === LabResult::CRITICAL_AUTO ? LabResult::CRITICAL_DISMISSED : null);

            $result->update([
                'is_critical' => $critical,
                'critical_source' => $source,
                'critical_flagged_at' => $critical ? now() : null,
                'critical_flagged_by' => $critical ? $actor->getKey() : null,
            ]);

            return $result->fresh();
        });
    }
}
