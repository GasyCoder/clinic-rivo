<?php

namespace App\Actions\Laboratory;

use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Laboratory\AnalysisReferenceResolver;
use App\Services\Laboratory\LabResultSummary;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — le technicien termine l'analyse : le résultat est rendu
 * (`resulted_at`, `result_value` composé), et l'analyse attend la validation
 * du biologiste. Les prescripteurs le lisent dès maintenant, marqué « non
 * validé » tant que la validation n'a pas eu lieu.
 *
 * Refusé quand rien n'est saisi, ou quand une culture « présence de germe(s) »
 * ne nomme aucun germe : un résultat vide n'est pas un résultat.
 */
class CompleteLabItemAction
{
    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly LabResultSummary $summary,
        private readonly AnalysisReferenceResolver $references,
    ) {}

    public function execute(LabRequestItem $item, User $actor): LabRequestItem
    {
        if ($actor->cannot('laboratory_results.create')) {
            throw new AuthorizationException('Terminer une analyse demande le droit « laboratory_results.create ».');
        }

        return DB::transaction(function () use ($item, $actor): LabRequestItem {
            $locked = LabItemGuard::lockWorkable($item);
            $locked->load(['results', 'antibiograms.results']);

            $filled = $locked->results->reject->isBlank();
            if ($filled->isEmpty()) {
                throw ValidationException::withMessages(['item' => 'Aucun résultat n’est saisi : il n’y a rien à rendre.']);
            }

            foreach ($filled as $result) {
                if ($result->entry_mode === LabEntryMode::Culture->value
                    && $result->value === LabEntryOptions::CULTURE_GROWTH
                    && empty($result->selections['bacteria'] ?? [])) {
                    throw ValidationException::withMessages(['item' => "{$result->designation_snapshot} : nommez au moins un germe identifié."]);
                }
                if ($result->entry_mode === LabEntryMode::Nugent->value && $result->value === null) {
                    throw ValidationException::withMessages(['item' => "{$result->designation_snapshot} : les trois sous-scores de Nugent sont nécessaires."]);
                }
            }

            $locked->update([
                'result_value' => $this->summary->compose($locked),
                'reference_snapshot' => $this->references->snapshot(
                    $locked->catalogItem()->firstOrFail(),
                    $this->workbench->patient($locked),
                    $this->workbench->referenceDate($locked),
                ),
                'resulted_at' => now(),
                'resulted_by' => $actor->getKey(),
                'status' => LabItemStatus::Completed,
            ]);

            return $locked->fresh();
        });
    }
}
