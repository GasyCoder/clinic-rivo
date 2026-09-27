<?php

namespace App\Actions\Reception;

use App\Enums\AdministrativeExitType;
use App\Models\Episode;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sélection multiple de l'écran « Sorties & règlements ».
 *
 * Chaque passage est traité **séparément**, par l'action qui le traite déjà
 * seul : les règles ne sont pas réécrites pour le lot, et c'est le serveur qui
 * décide de chaque passage, jamais la liste cochée à l'écran. Un passage
 * refusé — compte plus soldé, prestation apparue entre-temps, sortie déjà
 * prononcée — n'empêche pas les autres : ce sont des dossiers indépendants,
 * pas les lignes d'une même écriture. Le rapport dit ce qui est passé et ce qui
 * ne l'est pas, avec la raison.
 *
 * Un audit par passage (les actions le font), plus une ligne de synthèse.
 *
 * Seule la sortie « payé comptant » se prononce en lot. Une dette validée exige
 * un responsable identifié, une évasion un constat : ni l'un ni l'autre ne se
 * décide sur une liste.
 */
class BulkSettlementAction
{
    public function __construct(
        private readonly RecordAdministrativeExitAction $exit,
        private readonly InvoicePendingPrestationsAction $invoice,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  Collection<int, Episode>  $episodes
     * @return array{action: string, total: int, done: int, failed: array<int, array{episode_number: string, patient: string, message: string}>}
     */
    public function paidCashExit(Collection $episodes, User $actor): array
    {
        $report = $this->run('exit', $episodes, function (Episode $episode) use ($actor): void {
            $this->exit->execute($episode, [
                'exit_type' => AdministrativeExitType::PaidCash->value,
                // Vide : le motif est composé par le serveur à partir du compte
                // qu'il recalcule sous verrou.
                'reason' => '',
            ], $actor);
        });

        $this->summarize('settlement.bulk_exit', $report, $episodes, $actor);

        return $report;
    }

    /**
     * @param  Collection<int, Episode>  $episodes
     * @return array{action: string, total: int, done: int, failed: array<int, array{episode_number: string, patient: string, message: string}>}
     */
    public function invoicePending(Collection $episodes, User $actor): array
    {
        $report = $this->run('invoice', $episodes, function (Episode $episode) use ($actor): void {
            $result = $this->invoice->execute($episode, $actor);

            if ($result === null) {
                throw ValidationException::withMessages(['episode' => 'Aucune prestation en attente de facturation.']);
            }
        });

        $this->summarize('settlement.bulk_invoice', $report, $episodes, $actor);

        return $report;
    }

    /**
     * @param  Collection<int, Episode>  $episodes
     * @return array{action: string, total: int, done: int, failed: array<int, array{episode_number: string, patient: string, message: string}>}
     */
    private function run(string $action, Collection $episodes, callable $handle): array
    {
        $failed = [];
        $done = 0;

        foreach ($episodes as $episode) {
            try {
                $handle($episode);
                $done++;
            } catch (ValidationException $exception) {
                $failed[] = $this->failure($episode, (string) collect($exception->errors())->flatten()->first());
            } catch (AuthorizationException $exception) {
                $failed[] = $this->failure($episode, $exception->getMessage());
            }
        }

        return ['action' => $action, 'total' => $episodes->count(), 'done' => $done, 'failed' => $failed];
    }

    /** @return array{episode_number: string, patient: string, message: string} */
    private function failure(Episode $episode, string $message): array
    {
        return [
            'episode_number' => $episode->episode_number,
            'patient' => trim(($episode->patient?->last_name ?? '').' '.($episode->patient?->first_name ?? '')),
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, Episode>  $episodes
     */
    private function summarize(string $auditAction, array $report, Collection $episodes, User $actor): void
    {
        $this->auditor->record(
            $auditAction,
            newValues: [
                'episodes' => $episodes->pluck('episode_number')->all(),
                'done' => $report['done'],
                'failed' => count($report['failed']),
            ],
            module: 'reception',
            actor: $actor,
        );
    }
}
