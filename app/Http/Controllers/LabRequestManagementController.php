<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\ArchiveLabRequestAction;
use App\Actions\Laboratory\BulkLabRequestAction;
use App\Actions\Laboratory\EditLabRequestAction;
use App\Actions\Laboratory\TrashLabRequestAction;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-220 — ranger, corriger et mettre à la corbeille les demandes d'analyses,
 * une à une ou en lot. Chaque geste est jugé par son action ; ce contrôleur ne
 * fait que lire la requête et dire le résultat.
 */
class LabRequestManagementController extends Controller
{
    public function archive(Request $request, LabRequest $labRequest, ArchiveLabRequestAction $action): RedirectResponse
    {
        $action->archive($labRequest, $request->user());

        return back()->with('status', 'Demande archivée : elle se retrouve dans « Archivées ».');
    }

    public function unarchive(Request $request, LabRequest $labRequest, ArchiveLabRequestAction $action): RedirectResponse
    {
        $action->unarchive($labRequest, $request->user());

        return back()->with('status', 'Demande sortie des archives.');
    }

    public function trash(Request $request, LabRequest $labRequest, TrashLabRequestAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], $this->reasonMessages('la demande part à la corbeille'));
        $result = $action->execute($labRequest, $validated['reason'], $request->user());

        // Après la corbeille, la demande n'existe plus pour personne : retour à la file.
        return redirect('/laboratory')
            ->with('status', 'Demande mise à la corbeille.'.$this->invoicedNote($result['invoiced']))
            ->with('status_type', $result['invoiced'] > 0 ? 'warning' : 'success');
    }

    public function bulk(Request $request, BulkLabRequestAction $bulk): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(BulkLabRequestAction::ACTIONS)],
            'uuids' => ['required', 'array', 'min:1', 'max:'.BulkLabRequestAction::MAX],
            'uuids.*' => ['required', 'string', 'uuid', 'distinct'],
            'reason' => [Rule::requiredIf(fn () => $request->input('action') === 'trash'), 'nullable', 'string', 'min:3', 'max:1000'],
        ], [
            'uuids.required' => 'Cochez au moins une demande.',
            'uuids.max' => 'Au plus '.BulkLabRequestAction::MAX.' demandes à la fois.',
            ...$this->reasonMessages('les demandes partent à la corbeille'),
        ]);

        $requests = LabRequest::query()
            ->whereIn('uuid', $validated['uuids'])
            ->with('episode.patient:id,first_name,last_name')
            ->get();

        $report = $bulk->execute($validated['action'], $requests, $validated['reason'] ?? null, $request->user());
        $failed = count($report['failed']);
        $what = match ($report['action']) {
            'archive' => 'archivée',
            'unarchive' => 'sortie des archives',
            default => 'mise à la corbeille',
        };
        $plural = fn (int $count, string $word) => $count.' '.$word.($count > 1 ? 's' : '');

        return back()
            ->with('status', ($failed === 0
                ? $plural($report['done'], 'demande').' '.$what.($report['done'] > 1 && $report['action'] !== 'unarchive' ? 's' : '').'.'
                : "{$report['done']} sur {$report['total']} : {$what}. ".$plural($failed, 'refus').' — détail ci-dessous.')
                .$this->invoicedNote($report['invoiced']))
            ->with('status_type', match (true) {
                $report['done'] === 0 => 'danger',
                $failed > 0 || $report['invoiced'] > 0 => 'warning',
                default => 'success',
            })
            ->with('bulk_report', $report);
    }

    public function updateNotes(Request $request, LabRequest $labRequest, EditLabRequestAction $action): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']], [
            'notes.max' => 'Les renseignements ne dépassent pas 2 000 caractères.',
        ]);
        $action->updateNotes($labRequest, $validated['notes'] ?? null, $request->user());

        return back()->with('status', 'Renseignements cliniques mis à jour.');
    }

    public function addItems(Request $request, LabRequest $labRequest, EditLabRequestAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'catalog_item_uuids' => ['required', 'array', 'min:1', 'max:20'],
            'catalog_item_uuids.*' => ['required', 'string', 'uuid', 'distinct'],
        ], ['catalog_item_uuids.required' => 'Choisissez au moins une analyse.']);

        $created = $action->addItems($labRequest, $validated['catalog_item_uuids'], $request->user());

        return back()->with('status', count($created) === 1
            ? "« {$created[0]->catalog_item_name_snapshot} » ajoutée à la demande."
            : count($created).' analyses ajoutées à la demande.');
    }

    public function removeItem(Request $request, LabRequestItem $labRequestItem, EditLabRequestAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], $this->reasonMessages('l’analyse est retirée'));
        $removed = $action->removeItem($labRequestItem, $validated['reason'], $request->user());

        return back()->with('status', "« {$removed->catalog_item_name_snapshot} » retirée de la demande.");
    }

    /** @return array<string, string> */
    private function reasonMessages(string $what): array
    {
        return [
            'reason.required' => "Indiquez pourquoi {$what}.",
            'reason.min' => "Indiquez pourquoi {$what}.",
        ];
    }

    private function invoicedNote(int $invoiced): string
    {
        return match (true) {
            $invoiced === 0 => '',
            $invoiced === 1 => ' Une analyse était déjà sur une facture : la Caisse la régularise.',
            default => " {$invoiced} analyses étaient déjà sur une facture : la Caisse les régularise.",
        };
    }
}
