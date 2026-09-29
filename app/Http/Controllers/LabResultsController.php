<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\ReturnLabItemAction;
use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Laboratory\LabRequestPresenter;
use App\Services\Laboratory\LabResultAccess;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-216 — les résultats d'analyses tels que le médecin les reçoit.
 *
 * La même feuille que celle du laboratoire, mais seulement ce qui a été envoyé :
 * une saisie en cours, ou un résultat rendu que le technicien n'a pas encore
 * envoyé, reste au laboratoire. Une analyse reprise pour être refaite montre la
 * valeur envoyée, marquée « en correction » — jamais la saisie qui la remplace.
 *
 * Adressée à un confrère, la feuille ne sert aucune valeur tant que le lecteur
 * n'a pas confirmé ; l'ouverture est tracée (`LabResultAccess`).
 */
class LabResultsController extends Controller
{
    public function show(
        Request $request,
        LabRequest $labRequest,
        LabWorkbench $workbench,
        LabRequestPresenter $presenter,
        LabResultAccess $access,
    ): Response {
        $labRequest->load([
            'items', 'episode.patient', 'requestedBy:id,name', 'resultsRecipient:id,name', 'recipients:users.id,users.name', 'resultsAddressedBy:id,name',
            'hospitalStay:id,uuid', 'maternityRecord.orientation:id,uuid', 'consultation.orientation:id,uuid',
        ]);
        $user = $request->user();
        $sealed = $access->sealFor($labRequest, $user);
        $delivered = $labRequest->items->sortBy('id')->filter(fn (LabRequestItem $item) => $item->isDelivered());

        $header = $presenter->header($labRequest);
        if ($sealed !== null || $delivered->isEmpty()) {
            // La conclusion générale est du contenu clinique : elle suit les valeurs.
            $header = ['conclusion' => null, 'conclusion_at' => null, 'conclusion_by' => null] + $header;
        }

        return Inertia::render('Laboratory/ResultsPrint', [
            'labRequest' => $header + [
                'recipient' => $labRequest->recipientNames(),
                'addressed_at' => $labRequest->results_addressed_at,
                'addressed_by' => $labRequest->resultsAddressedBy?->name,
            ],
            'context' => ['mode' => 'physician', ...$this->back($labRequest, $user)],
            'sealed' => $sealed,
            'samples' => $sealed ? [] : collect($presenter->samples($labRequest))->whereNull('rejected')->values(),
            'items' => $sealed ? [] : $delivered->map(fn (LabRequestItem $item) => $this->item($item, $workbench))->values(),
            'pending' => $labRequest->items->reject(fn (LabRequestItem $item) => $item->isDelivered())
                ->pluck('catalog_item_name_snapshot')->values(),
            'options' => LabEntryOptions::forScreen(),
            // Amendement ADR-216 du 2026-09-29 — ce que le médecin peut faire, droit par droit :
            // demander qu'un résultat soit refait, ou ouvrir la demande au laboratoire
            // pour la modifier ou y saisir, quand ces droits lui sont accordés.
            'can' => [
                'return' => $sealed === null && $user->can(ReturnLabItemAction::PERMISSION),
                'bench_url' => $labRequest->cancelled_at === null && $user->can('laboratory_results.view')
                    && ($user->can('laboratory_results.create') || $user->can('laboratory_orders.update'))
                    ? "/laboratory/requests/{$labRequest->uuid}" : null,
                'bench_label' => $user->can('laboratory_results.create') ? 'Saisir au laboratoire' : 'Modifier la demande',
            ],
        ]);
    }

    /** Ouvrir un résultat adressé à un confrère, après confirmation : tracé, valable pour la session. */
    public function open(Request $request, LabRequest $labRequest, LabResultAccess $access): RedirectResponse
    {
        $labRequest->loadMissing('resultsRecipient:id,name', 'recipients:users.id,users.name');
        $access->open($labRequest, $request->user());

        return back(fallback: "/resultats-analyses/{$labRequest->uuid}");
    }

    /** @return array<string, mixed> */
    private function item(LabRequestItem $item, LabWorkbench $workbench): array
    {
        if ($item->currentStatus() === LabItemStatus::Validated) {
            return $workbench->present($item);
        }

        // Reprise par le laboratoire : la valeur envoyée, sans la saisie en cours.
        $item->loadMissing('returnedBy:id,name');

        return [
            'uuid' => $item->uuid,
            'name' => $item->catalog_item_name_snapshot,
            'status' => LabItemStatus::ToRedo->value,
            'in_correction' => true,
            'has_definitions' => false,
            'nodes' => [],
            'result_value' => $item->result_value,
            'result_notes' => $item->result_notes,
            'resulted_at' => $item->resulted_at,
            'return_reason' => $item->return_reason,
            'returned_at' => $item->returned_at,
            'returned_by' => $item->returnedBy?->name,
        ];
    }

    /**
     * D'où l'on vient : le dossier qui a demandé l'analyse, quand le lecteur peut
     * l'ouvrir ; sinon la liste des demandes d'examens.
     *
     * @return array{back_href: string, back_label: string}
     */
    private function back(LabRequest $labRequest, User $user): array
    {
        return match (true) {
            $labRequest->hospitalStay !== null && $user->can('hospitalization.view') => [
                'back_href' => "/hospitalisation/{$labRequest->hospitalStay->uuid}", 'back_label' => 'Retour au séjour',
            ],
            $labRequest->maternityRecord?->orientation !== null && $user->can('maternity.view') => [
                'back_href' => "/maternity/orientations/{$labRequest->maternityRecord->orientation->uuid}", 'back_label' => 'Retour au dossier Maternité',
            ],
            $labRequest->consultation?->orientation !== null && $user->can('consultations.view') => [
                'back_href' => "/medicine/orientations/{$labRequest->consultation->orientation->uuid}/paraclinique", 'back_label' => 'Retour à la consultation',
            ],
            $user->can('paraclinical_requests.view') => ['back_href' => '/medicine/demandes-examens', 'back_label' => 'Demandes d’examens'],
            default => ['back_href' => '/notifications', 'back_label' => 'Notifications'],
        };
    }
}
