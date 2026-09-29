<?php

namespace App\Actions\Laboratory;

use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Notifications\LabResultsAddressed;
use App\Services\Audit\Auditor;
use App\Services\Laboratory\AnalysisReferenceResolver;
use App\Services\Laboratory\LabResultRecipients;
use App\Services\Laboratory\LabResultSummary;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-216 — le technicien envoie les résultats au médecin, et cet envoi les
 * valide : il n'y a plus de biologiste distinct (les médecins de la clinique le
 * sont). Remplace « Terminer » puis « Valider » de l'ADR-213.
 *
 * Un seul geste, tout ou rien : chaque analyse choisie est rendue (résultat
 * composé, référence figée) puis marquée envoyée ; la demande garde à qui ses
 * résultats sont adressés. Une analyse refusée — rien de saisi, germe non
 * nommé, Nugent incomplet — n'en laisse partir aucune, et le message la nomme.
 *
 * Envoyée, une analyse ne se modifie plus : une erreur se corrige par « Renvoyer
 * à refaire » avec un motif (`ReturnLabItemAction`), puis un nouvel envoi —
 * jamais par une réécriture (ADR-010).
 */
class SendLabResultsAction
{
    public const PERMISSION = 'laboratory_results.validate';

    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly LabResultSummary $summary,
        private readonly AnalysisReferenceResolver $references,
        private readonly LabResultRecipients $recipients,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>  $itemUuids
     * @return array{count: int, recipient: ?User, corrected: bool}
     */
    public function execute(LabRequest $request, array $itemUuids, ?string $recipientUuid, bool $toNobody, User $actor): array
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Envoyer un résultat au médecin demande le droit « '.self::PERMISSION.' ».');
        }

        $recipient = $this->recipient($recipientUuid, $toNobody);

        $result = DB::transaction(function () use ($request, $itemUuids, $recipient, $actor): array {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['items' => 'Cette demande a été retirée par le prescripteur : elle ne s’envoie plus.']);
            }
            // ADR-217 — envoyer prend la demande en charge si personne ne l'a encore fait.
            LabItemGuard::ensureTakenUp($locked, $actor);

            $items = $locked->items()->whereIn('uuid', array_values(array_unique($itemUuids)))->orderBy('id')->get();

            if ($items->count() !== count(array_unique($itemUuids))) {
                throw ValidationException::withMessages(['items' => 'Une analyse choisie n’appartient pas à cette demande.']);
            }

            $corrected = false;
            $names = [];

            foreach ($items as $item) {
                $corrected = $this->send(LabItemGuard::lock($item), $actor) || $corrected;
                $names[] = $item->catalog_item_name_snapshot;
            }

            $before = [
                'recipient' => $locked->resultsRecipient?->name,
                'addressed' => $locked->results_addressed_at !== null,
            ];

            $locked->update([
                'results_recipient_id' => $recipient?->getKey(),
                'results_addressed_at' => now(),
                'results_addressed_by' => $actor->getKey(),
            ]);

            $this->auditor->record(
                'laboratory.results.send',
                $locked,
                [
                    'analyses' => $names,
                    'recipient' => $recipient?->name,
                    'to_nobody' => $recipient === null,
                    'corrected' => $corrected,
                ],
                $before,
                module: 'laboratory',
                actor: $actor,
            );

            return ['count' => $items->count(), 'names' => $names, 'corrected' => $corrected, 'request' => $locked->fresh(['episode.patient'])];
        });

        // Après l'écriture : une notification ne doit jamais annoncer un envoi annulé.
        if ($recipient !== null && (int) $recipient->getKey() !== (int) $actor->getKey()) {
            $recipient->notify(new LabResultsAddressed(
                request: $result['request'],
                analyses: $result['names'],
                sender: $actor->name,
                corrected: $result['corrected'],
            ));
        }

        return ['count' => $result['count'], 'recipient' => $recipient, 'corrected' => $result['corrected']];
    }

    private function recipient(?string $uuid, bool $toNobody): ?User
    {
        if ($toNobody) {
            return null;
        }

        if (blank($uuid)) {
            throw ValidationException::withMessages(['recipient_uuid' => 'Choisissez le médecin destinataire, ou « Aucun médecin » pour un patient externe.']);
        }

        $user = User::query()->where('uuid', $uuid)->first();

        if (! $this->recipients->isRecipient($user)) {
            throw ValidationException::withMessages(['recipient_uuid' => 'Ce compte ne peut pas recevoir de résultats d’analyse : il doit pouvoir prescrire et lire des analyses.']);
        }

        return $user;
    }

    /**
     * Rend puis marque envoyée une analyse verrouillée.
     *
     * @return bool vrai quand l'analyse avait déjà été rendue puis reprise : c'est une correction
     */
    private function send(LabRequestItem $locked, User $actor): bool
    {
        $status = $locked->currentStatus();
        $name = $locked->catalog_item_name_snapshot;

        if ($status === LabItemStatus::Validated) {
            throw ValidationException::withMessages(['items' => "« {$name} » est déjà envoyée au médecin."]);
        }

        // Rendue « en un bloc » (analyse sans définition), ou terminée avant
        // l'ADR-216 : le résultat est déjà composé, il ne reste qu'à l'envoyer.
        if ($status === LabItemStatus::Completed) {
            $locked->update([
                'status' => LabItemStatus::Validated,
                'validated_at' => now(),
                'validated_by' => $actor->getKey(),
                'sent_at' => now(),
            ]);

            return $locked->returned_at !== null;
        }

        $locked->load(['results', 'antibiograms.results']);
        $filled = $locked->results->reject->isBlank();

        if ($filled->isEmpty()) {
            throw ValidationException::withMessages(['items' => "« {$name} » : aucun résultat n’est saisi, il n’y a rien à envoyer."]);
        }

        foreach ($filled as $result) {
            if ($result->entry_mode === LabEntryMode::Culture->value
                && $result->value === LabEntryOptions::CULTURE_GROWTH
                && empty($result->selections['bacteria'] ?? [])) {
                throw ValidationException::withMessages(['items' => "« {$name} » — {$result->designation_snapshot} : nommez au moins un germe identifié."]);
            }
            if ($result->entry_mode === LabEntryMode::Nugent->value && $result->value === null) {
                throw ValidationException::withMessages(['items' => "« {$name} » — {$result->designation_snapshot} : les trois sous-scores de Nugent sont nécessaires."]);
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
            'status' => LabItemStatus::Validated,
            'validated_at' => now(),
            'validated_by' => $actor->getKey(),
            'sent_at' => now(),
        ]);

        return $locked->returned_at !== null;
    }
}
