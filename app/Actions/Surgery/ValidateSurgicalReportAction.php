<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalReport;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — valider le compte rendu opératoire **ne clôt plus le dossier**.
 *
 * Jusqu'ici, la validation du compte rendu appelait `complete()` : signer son
 * compte rendu suffisait donc à déclarer le dossier terminé, alors que le
 * SIGN OUT pouvait manquer, la sortie du bloc n'être pas renseignée et le
 * dossier d'anesthésie rester ouvert. Un seul métier clôturait pour tous.
 *
 * Ce sont deux faits distincts :
 *
 * ```text
 * compte rendu validé   le chirurgien a signé ce qu'il a fait
 * dossier clôturé       l'intervention est finie, le compte rendu signé, le
 *                       SIGN OUT confirmé, la sortie du bloc et l'anesthésie
 *                       documentées  →  CompleteSurgicalCaseAction
 * ```
 *
 * La garde de statut reste : un compte rendu ne se signe pas avant que
 * l'intervention ait commencé.
 */
class ValidateSurgicalReportAction
{
    public function execute(SurgicalReport $report): SurgicalReport
    {
        return DB::transaction(function () use ($report): SurgicalReport {
            $report = SurgicalReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if ($report->validated_at !== null) {
                throw ValidationException::withMessages([
                    'report' => 'Ce compte rendu opératoire est déjà validé.',
                ]);
            }

            $surgicalRequest = SurgicalRequest::query()->lockForUpdate()->findOrFail($report->surgical_request_id);

            if ($surgicalRequest->status !== SurgicalRequestStatus::InProgress) {
                throw ValidationException::withMessages([
                    'report' => 'Le compte rendu se valide une fois l’intervention démarrée.',
                ]);
            }

            $report->validated_by = Auth::id();
            $report->validated_at = now();
            $report->save();

            return $report->setRelation('surgicalRequest', $surgicalRequest);
        });
    }
}
