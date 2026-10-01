<?php

namespace App\Actions\Laboratory;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-216 — le technicien envoie les résultats au médecin, et cet envoi les
 * valide : il n'y a plus de biologiste distinct (les médecins de la clinique le
 * sont).
 *
 * Amendement du 2026-09-29 — « Terminer » (`CompleteLabItemAction`) rend chaque
 * analyse au pied de sa saisie ; l'envoi, en haut de la page, fait partir une,
 * plusieurs ou toutes les analyses terminées. Tout ou rien : une analyse pas
 * encore terminée n'en laisse partir aucune, et le message la nomme.
 *
 * Amendement quater — l'envoi n'est plus la fin : le médecin destinataire relit
 * et valide (`ApproveLabResultsAction`), et c'est seulement alors que la
 * Réception voit le résultat. Pour un patient externe (« Aucun médecin »),
 * l'envoi vaut validation.
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
        private readonly LabResultRecipients $recipientRule,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>  $itemUuids
     * @param  list<string>|string|null  $recipientUuids  un, plusieurs ou tous les médecins proposés
     * @return array{count: int, recipients: Collection<int, User>, recipient: ?User, corrected: bool}
     */
    public function execute(LabRequest $request, array $itemUuids, array|string|null $recipientUuids, bool $toNobody, User $actor): array
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Envoyer un résultat au médecin demande le droit « '.self::PERMISSION.' ».');
        }

        $recipients = $this->recipients(array_values(array_filter((array) $recipientUuids)), $toNobody);

        $result = DB::transaction(function () use ($request, $itemUuids, $recipients, $toNobody, $actor): array {
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
                $corrected = $this->send(LabItemGuard::lock($item), $actor, $toNobody) || $corrected;
                $names[] = $item->catalog_item_name_snapshot;
            }

            $before = [
                'recipient' => $locked->recipientNames(),
                'addressed' => $locked->results_addressed_at !== null,
            ];

            // Amendement ADR-216 du 2026-09-29 (ter) — les destinataires s'ajoutent à ceux déjà
            // servis : un médecin qui a reçu une première analyse continue de la lire librement.
            $now = now();
            foreach ($recipients as $recipient) {
                DB::table('lab_request_recipients')->insertOrIgnore([
                    'lab_request_id' => $locked->getKey(),
                    'user_id' => $recipient->getKey(),
                    'addressed_at' => $now,
                    'addressed_by' => $actor->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $locked->update([
                'results_recipient_id' => $locked->results_recipient_id ?? $recipients->first()?->getKey(),
                'results_addressed_at' => $now,
                'results_addressed_by' => $actor->getKey(),
            ]);
            $locked->unsetRelation('recipients');

            $this->auditor->record(
                'laboratory.results.send',
                $locked,
                [
                    'analyses' => $names,
                    'recipient' => $recipients->pluck('name')->implode(', ') ?: null,
                    'recipients' => $recipients->pluck('name')->all(),
                    'to_nobody' => $recipients->isEmpty(),
                    'corrected' => $corrected,
                ],
                $before,
                module: 'laboratory',
                actor: $actor,
            );

            return ['count' => $items->count(), 'names' => $names, 'corrected' => $corrected, 'request' => $locked->fresh(['episode.patient'])];
        });

        // Après l'écriture : une notification ne doit jamais annoncer un envoi annulé.
        foreach ($recipients as $recipient) {
            if ((int) $recipient->getKey() === (int) $actor->getKey()) {
                continue;
            }
            $recipient->notify(new LabResultsAddressed(
                request: $result['request'],
                analyses: $result['names'],
                sender: $actor->name,
                corrected: $result['corrected'],
            ));
        }

        return ['count' => $result['count'], 'recipients' => $recipients, 'recipient' => $recipients->first(), 'corrected' => $result['corrected']];
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, User>
     */
    private function recipients(array $uuids, bool $toNobody): Collection
    {
        if ($toNobody) {
            return collect();
        }

        if ($uuids === []) {
            throw ValidationException::withMessages(['recipient_uuid' => 'Choisissez au moins un médecin destinataire, ou « Aucun médecin » pour un patient externe.']);
        }

        $users = User::query()->whereIn('uuid', array_unique($uuids))->get();

        if ($users->count() !== count(array_unique($uuids)) || $users->contains(fn (User $user) => ! $this->recipientRule->isRecipient($user))) {
            throw ValidationException::withMessages(['recipient_uuid' => 'Un compte choisi ne peut pas recevoir de résultats d’analyse : il doit pouvoir prescrire et lire des analyses.']);
        }

        // Dans l'ordre choisi.
        return collect(array_unique($uuids))->map(fn (string $uuid) => $users->firstWhere('uuid', $uuid))->values();
    }

    /**
     * Rend puis marque envoyée une analyse verrouillée.
     *
     * @return bool vrai quand l'analyse avait déjà été rendue puis reprise : c'est une correction
     */
    private function send(LabRequestItem $locked, User $actor, bool $toNobody): bool
    {
        $status = $locked->currentStatus();
        $name = $locked->catalog_item_name_snapshot;

        if ($status === LabItemStatus::Validated) {
            throw ValidationException::withMessages(['items' => "« {$name} » est déjà envoyée au médecin."]);
        }

        // Amendement du 2026-09-29 — l'envoi ne part que d'une analyse terminée :
        // « Tout envoyer », coché par défaut, ne fait jamais partir une saisie à moitié faite.
        if ($status !== LabItemStatus::Completed) {
            throw ValidationException::withMessages(['items' => "« {$name} » n’est pas encore terminée : terminez-la d’abord, au pied de sa saisie."]);
        }

        // Une analyse reprise après un envoi a gardé, jusqu'ici, la valeur que le
        // médecin avait lue (« Terminer » ne la réécrit pas) : elle se recompose à
        // l'envoi. Rendue « en un bloc » (sans définition), elle garde son texte.
        $locked->load(['results', 'antibiograms.results']);
        $structured = $locked->isDelivered() && $locked->results->reject->isBlank()->isNotEmpty();

        $locked->update([
            ...($structured ? [
                'result_value' => $this->summary->compose($locked),
                'reference_snapshot' => $this->references->snapshot(
                    $locked->catalogItem()->firstOrFail(),
                    $this->workbench->patient($locked),
                    $this->workbench->referenceDate($locked),
                ),
                'resulted_at' => now(),
                'resulted_by' => $actor->getKey(),
            ] : []),
            'status' => LabItemStatus::Validated,
            'validated_at' => now(),
            'validated_by' => $actor->getKey(),
            'sent_at' => now(),
            // Amendement ADR-216 quater — un médecin valide ce qu'il reçoit. Adressé à
            // personne (patient externe), il n'y a pas de médecin : l'envoi vaut validation.
            // Une correction renvoyée se revalide : la valeur n'est plus celle qu'il a lue.
            'approved_at' => $toNobody ? now() : null,
            'approved_by' => $toNobody ? $actor->getKey() : null,
        ]);

        return $locked->returned_at !== null;
    }
}
