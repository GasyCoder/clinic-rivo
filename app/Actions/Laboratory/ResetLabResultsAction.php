<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabAnalysisNote;
use App\Models\LabAntibiogram;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-219 — remettre une analyse à zéro pendant la saisie : un technicien qui
 * s'est trompé de patient ou de tube repart d'une page vierge.
 *
 * Seulement une saisie : une analyse déjà envoyée au médecin a été lue, elle ne
 * s'efface jamais — elle se corrige ligne par ligne après « Renvoyer à refaire »
 * (ADR-216, ADR-010). Ce qui part : les résultats en cours, les antibiogrammes
 * et les notes de chaque ligne. L'audit garde ce qui a été effacé.
 */
class ResetLabResultsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(LabRequestItem $item, User $actor): LabRequestItem
    {
        if ($actor->cannot('laboratory_results.create')) {
            throw new AuthorizationException('Réinitialiser la saisie demande le droit « laboratory_results.create ».');
        }

        return DB::transaction(function () use ($item, $actor): LabRequestItem {
            $locked = LabItemGuard::lockEditable($item);

            if ($locked->sent_at !== null) {
                throw ValidationException::withMessages(['item' => 'Cette analyse a déjà été envoyée au médecin : elle ne se remet pas à zéro. Corrigez les lignes concernées.']);
            }

            $results = LabResult::query()->where('lab_request_item_id', $locked->id)->get();
            $notes = LabAnalysisNote::query()->where('lab_request_item_id', $locked->id)->get();
            $antibiograms = LabAntibiogram::query()->where('lab_request_item_id', $locked->id)->with('results')->get();

            if ($results->isEmpty() && $notes->isEmpty() && $antibiograms->isEmpty()) {
                throw ValidationException::withMessages(['item' => 'Rien n’est saisi sur cette analyse : il n’y a rien à réinitialiser.']);
            }

            $before = [
                'status' => $locked->currentStatus()->value,
                'results' => $results->map(fn (LabResult $result) => [
                    'analysis' => $result->designation_snapshot,
                    'value' => $result->value,
                    'selections' => $result->selections,
                    'interpretation' => $result->interpretation,
                ])->values()->all(),
                'notes' => $notes->pluck('note')->values()->all(),
                'antibiograms' => $antibiograms->pluck('bacterium_name_snapshot')->values()->all(),
            ];

            $antibiograms->each(function (LabAntibiogram $antibiogram): void {
                $antibiogram->results->each->delete();
                $antibiogram->delete();
            });
            $results->each->delete();
            $notes->each->delete();

            // Une analyse en cours redevient « à faire » ; une analyse à refaire le reste.
            if ($locked->currentStatus() === LabItemStatus::InProgress) {
                $locked->update(['status' => LabItemStatus::Pending]);
            }

            $this->auditor->record(
                'laboratory.results.reset',
                $locked,
                ['status' => $locked->currentStatus()->value, 'results' => [], 'notes' => [], 'antibiograms' => []],
                $before,
                module: 'laboratory',
                actor: $actor,
            );

            return $locked->fresh();
        });
    }
}
