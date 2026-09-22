<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Surgery\SurgicalReadinessGate;
use Illuminate\Support\Facades\DB;

/**
 * ADR-170 — clore le dossier chirurgical : un acte explicite, distinct de la
 * validation du compte rendu (voir `ValidateSurgicalReportAction`).
 *
 * `SurgicalReadinessGate::blockersForCompletion()` est la seule autorité de ce
 * qui manque encore ; l'Action ne recopie aucune règle. Elle est idempotente :
 * un double clic sur un dossier déjà clôturé le renvoie tel quel plutôt que
 * d'échouer sur une transition invalide.
 */
class CompleteSurgicalCaseAction
{
    public function __construct(private readonly SurgicalReadinessGate $gate) {}

    public function execute(SurgicalRequest $surgicalRequest, User $actor): SurgicalRequest
    {
        return DB::transaction(function () use ($surgicalRequest, $actor): SurgicalRequest {
            $case = SurgicalRequest::query()
                ->with(['intervention', 'report', 'anesthesiaRecord', 'safetyChecklists.confirmations', 'blockExit', 'teamMembers'])
                ->lockForUpdate()
                ->findOrFail($surgicalRequest->getKey());

            if (in_array($case->status, [SurgicalRequestStatus::Completed, SurgicalRequestStatus::Discharged], true)) {
                return $case;
            }

            $this->gate->assertCanComplete($case, $actor);

            $case->complete();

            return $case;
        });
    }
}
