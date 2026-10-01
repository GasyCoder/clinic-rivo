<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — la sélection multiple de la paillasse et de la feuille de paillasse.
 *
 * Chaque demande est jugée **séparément**, par l'action qui la juge seule : les
 * règles ne sont pas réécrites pour le lot. Une demande refusée — encore en cours,
 * déjà envoyée au médecin — n'empêche pas les autres ; le rapport dit ce qui est
 * passé et ce qui ne l'est pas, avec la raison (même principe que l'ADR-090).
 */
class BulkLabRequestAction
{
    public const ACTIONS = ['archive', 'unarchive', 'trash'];

    public const MAX = 50;

    public function __construct(
        private readonly ArchiveLabRequestAction $archive,
        private readonly TrashLabRequestAction $trash,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  Collection<int, LabRequest>  $requests
     * @return array{action: string, total: int, done: int, invoiced: int, failed: array<int, array{label: string, message: string}>}
     */
    public function execute(string $action, Collection $requests, ?string $reason, User $actor): array
    {
        $invoiced = 0;
        $done = 0;
        $failed = [];

        foreach ($requests as $request) {
            try {
                match ($action) {
                    'archive' => $this->archive->archive($request, $actor),
                    'unarchive' => $this->archive->unarchive($request, $actor),
                    'trash' => $invoiced += $this->trash->execute($request, $reason, $actor)['invoiced'],
                };
                $done++;
            } catch (ValidationException $exception) {
                $failed[] = ['label' => $this->label($request), 'message' => collect($exception->errors())->flatten()->first() ?? 'Refusé.'];
            } catch (AuthorizationException $exception) {
                // Le même droit vaut pour toutes : inutile de le redire N fois.
                throw $exception;
            }
        }

        $report = ['action' => $action, 'total' => $requests->count(), 'done' => $done, 'invoiced' => $invoiced, 'failed' => $failed];

        $this->auditor->record('laboratory.requests.bulk_'.$action, newValues: [
            'requests' => $requests->pluck('uuid')->all(),
            'done' => $done,
            'failed' => count($failed),
        ], reason: $action === 'trash' ? $reason : null, module: 'clinical_flow', actor: $actor);

        return $report;
    }

    private function label(LabRequest $request): string
    {
        $patient = $request->episode?->patient;
        $name = $patient ? trim(mb_strtoupper((string) $patient->last_name).' '.$patient->first_name) : 'Patient';

        return $name.' · '.($request->lab_number ?? $request->episode?->episode_number ?? 'demande');
    }
}
