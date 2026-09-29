<?php

namespace App\Actions\Laboratory;

use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Laboratory\AnalysisReferenceResolver;
use App\Services\Laboratory\LabResultSummary;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-216, amendement du 2026-09-29 — « Terminer l'analyse », au pied de la
 * saisie : l'analyse est rendue (résultat composé, référence figée) et la liste
 * des tâches la marque « Terminée ». L'envoi au médecin est un geste à part, en
 * haut de la page, pour une, plusieurs ou toutes les analyses terminées.
 *
 * Une analyse déjà envoyée puis reprise garde la valeur que le médecin a lue
 * tant qu'elle n'est pas renvoyée : « Terminer » ne la réécrit pas, l'envoi la
 * recompose (`SendLabResultsAction`).
 */
class CompleteLabItemAction
{
    public const PERMISSION = 'laboratory_results.create';

    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly LabResultSummary $summary,
        private readonly AnalysisReferenceResolver $references,
        private readonly Auditor $auditor,
    ) {}

    public function execute(LabRequestItem $item, User $actor): LabRequestItem
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Terminer une analyse demande le droit « '.self::PERMISSION.' ».');
        }

        return DB::transaction(function () use ($item, $actor): LabRequestItem {
            $locked = LabItemGuard::lockWorkable($item, $actor);
            $before = $locked->currentStatus();
            $this->assertRenderable($locked);

            $attributes = ['status' => LabItemStatus::Completed];

            if (! $locked->isDelivered()) {
                $attributes += [
                    'result_value' => $this->summary->compose($locked),
                    'reference_snapshot' => $this->references->snapshot(
                        $locked->catalogItem()->firstOrFail(),
                        $this->workbench->patient($locked),
                        $this->workbench->referenceDate($locked),
                    ),
                    'resulted_at' => now(),
                    'resulted_by' => $actor->getKey(),
                ];
            }

            $locked->update($attributes);

            $this->auditor->record(
                'laboratory.item.complete',
                $locked,
                ['status' => LabItemStatus::Completed->value, 'analysis' => $locked->catalog_item_name_snapshot],
                ['status' => $before->value],
                module: 'laboratory',
                actor: $actor,
            );

            return $locked->fresh();
        });
    }

    /** Ce qui doit être saisi pour qu'une analyse se termine ; le message nomme ce qui manque. */
    public static function assertRenderable(LabRequestItem $locked): void
    {
        $name = $locked->catalog_item_name_snapshot;
        $locked->load(['results', 'antibiograms.results']);
        $filled = $locked->results->reject->isBlank();

        if ($filled->isEmpty()) {
            throw ValidationException::withMessages(['item' => "« {$name} » : aucun résultat n’est saisi, il n’y a rien à terminer."]);
        }

        foreach ($filled as $result) {
            if ($result->entry_mode === LabEntryMode::Culture->value
                && $result->value === LabEntryOptions::CULTURE_GROWTH
                && empty($result->selections['bacteria'] ?? [])) {
                throw ValidationException::withMessages(['item' => "« {$name} » — {$result->designation_snapshot} : nommez au moins un germe identifié."]);
            }
            if ($result->entry_mode === LabEntryMode::Nugent->value && $result->value === null) {
                throw ValidationException::withMessages(['item' => "« {$name} » — {$result->designation_snapshot} : les trois sous-scores de Nugent sont nécessaires."]);
            }
        }
    }
}
