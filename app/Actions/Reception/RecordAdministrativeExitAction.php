<?php

namespace App\Actions\Reception;

use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\PatientDebt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\Reception\EpisodeAccountControl;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CDC §33.3 — the administrative exit, Réception/Caisse's own decision,
 * distinct from and never a consequence of the medical discharge (§33.1,
 * ADR-035). Closing the consultation left the passage in
 * PENDING_SETTLEMENT (ADR-054/084); this is what ends it.
 *
 * Which exit is legal is decided here from the account, never by the
 * caller (§34.1 rule 6, §34.1 rule 11):
 *
 *     reste à payer = 0  -> PAID_CASH only
 *     reste à payer > 0  -> DEBT_VALIDATED (authorised derogation)
 *                           or ESCAPED (observed fact) only
 *
 * The balance is recomputed under lock from EpisodeAccountControl — a
 * figure the browser displayed a minute ago is never trusted, because a
 * payment or a new act may have landed since.
 */
class RecordAdministrativeExitAction
{
    public function __construct(
        private readonly EpisodeAccountControl $accounts,
        private readonly FinancialNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{
     *     exit_type: string, reason: string, comment?: ?string,
     *     responsible_name?: ?string, responsible_phone?: ?string,
     *     responsible_relationship?: ?string, due_date?: ?string,
     *     left_at_estimate?: ?string, last_known_service?: ?string
     * }  $data
     */
    public function execute(Episode $episode, array $data, User $actor): Episode
    {
        $type = AdministrativeExitType::from($data['exit_type']);

        // A debt exit is the authorised derogation of §34.1 rule 6, not an
        // ordinary cash-desk act: it commits the clinic to an unpaid
        // balance, so it needs the dedicated right even for an account
        // that may otherwise record exits.
        if ($type === AdministrativeExitType::DebtValidated && ! $actor->can('debts.authorize')) {
            throw new AuthorizationException(
                'Une sortie avec dette validée doit être autorisée par une personne habilitée (permission debts.authorize).',
            );
        }

        return DB::transaction(function () use ($episode, $data, $actor, $type): Episode {
            /** @var Episode $locked */
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());

            $this->guardEpisodeState($locked);

            $account = $this->accounts->summarize($locked);
            $balanceMinor = Money::toMinor($account['balance_amount']);

            $this->guardExitAgainstBalance($type, $balanceMinor, $account['balance_amount']);

            // Le motif, composé ici plutôt que tapé — mais seulement quand
            // l'agent n'a rien écrit. Il est produit **après** le verrou, à
            // partir du compte que cette transaction vient de recalculer :
            // une phrase générée ne peut donc jamais annoncer un montant que
            // le compte contredit.
            $reason = filled($data['reason'] ?? null)
                ? trim((string) $data['reason'])
                : $this->describeExit($type, $account, $data);

            $previous = [
                'status' => $locked->status->value,
                'administrative_status' => $locked->administrative_status?->value,
            ];

            $locked->fill([
                'administrative_status' => $type->administrativeStatus(),
                'administrative_exit_type' => $type,
                'administrative_exit_at' => now(),
                'administrative_exit_by' => $actor->getKey(),
                // §34.2 rule 8 — what was owed when the patient walked out,
                // frozen. Later payments never rewrite this.
                'administrative_exit_balance' => $account['balance_amount'],
                'administrative_exit_reason' => $reason,
                // The passage is over: CDC §32 collapses all three exits to
                // the single global state "Clos".
                'status' => EpisodeStatus::Closed,
                'ended_at' => now(),
            ]);
            $locked->save();

            $debt = $type->createsDebt()
                ? $this->createDebt($locked, $data, $reason, $actor, $type, $account['balance_amount'], $account['currency'])
                : null;

            $this->auditor->record(
                'episode.administrative_exit',
                entity: $locked,
                newValues: [
                    'administrative_status' => $type->administrativeStatus()->value,
                    'administrative_exit_type' => $type->value,
                    'administrative_exit_balance' => $account['balance_amount'],
                    'status' => EpisodeStatus::Closed->value,
                    'debt_number' => $debt?->debt_number,
                ],
                oldValues: $previous,
                reason: $reason,
                module: 'reception',
                actor: $actor,
            );

            return $locked->fresh(['patient', 'debts']);
        });
    }

    private function guardEpisodeState(Episode $locked): void
    {
        if ($locked->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages([
                'exit_type' => $locked->administrative_status?->isDischarged()
                    ? 'Ce passage a déjà fait l’objet d’une sortie administrative.'
                    : 'Ce passage n’est plus ouvert : aucune sortie administrative n’est possible.',
            ]);
        }

        // §33.3: the administrative exit comes *after* the medical one. A
        // passage still in care is still someone's patient — Réception
        // closing it would leave a Soins or Médecine queue holding a
        // passage nobody can act on any more. Recording an escape that
        // happened mid-care would additionally require cancelling those
        // active orientations: a rule the CDC does not state, so it is not
        // invented here.
        if ($locked->administrative_status !== EpisodeAdministrativeStatus::PendingSettlement) {
            throw ValidationException::withMessages([
                'exit_type' => 'Ce passage n’est pas encore en attente de règlement : la sortie médicale ou la fin des soins doit être prononcée avant la sortie administrative.',
            ]);
        }
    }

    /**
     * Le motif par défaut : ce que le compte dit, en une phrase.
     *
     * Le CDC §34.1 règle 8 exige qu'une opération sensible porte sa cause.
     * Il n'exige pas qu'un agent la tape : une phrase composée des chiffres
     * réellement constatés la porte tout aussi bien, et mieux qu'un
     * « RAS » saisi pour franchir un champ obligatoire.
     *
     * Elle n'est utilisée que si l'agent n'a rien écrit. Ce qu'il écrit
     * l'emporte toujours : lui seul connaît les circonstances qu'aucun
     * calcul ne produit.
     *
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $data
     */
    private function describeExit(AdministrativeExitType $type, array $account, array $data): string
    {
        $invoiced = $account['invoiced_amount'];
        $paid = $account['paid_amount'];
        $balance = $account['balance_amount'];

        return match ($type) {
            AdministrativeExitType::PaidCash => sprintf(
                'Compte soldé : %s facturés, %s réglés. Sortie prononcée après vérification du compte.',
                $invoiced,
                $paid,
            ),
            AdministrativeExitType::DebtValidated => sprintf(
                'Dérogation autorisée : reste à payer %s sur %s facturés, pris en charge par %s.',
                $balance,
                $invoiced,
                filled($data['responsible_name'] ?? null) ? trim((string) $data['responsible_name']) : 'un responsable identifié',
            ),
            AdministrativeExitType::Escaped => sprintf(
                'Départ constaté sans règlement régulier : reste à payer %s sur %s facturés%s.',
                $balance,
                $invoiced,
                filled($data['last_known_service'] ?? null)
                    ? ', dernier service connu : '.trim((string) $data['last_known_service'])
                    : '',
            ),
        };
    }

    private function guardExitAgainstBalance(
        AdministrativeExitType $type,
        int $balanceMinor,
        string $balance,
    ): void {
        if ($type === AdministrativeExitType::PaidCash && $balanceMinor !== 0) {
            throw ValidationException::withMessages([
                'exit_type' => "Le compte n’est pas soldé (reste à payer {$balance}). Encaissez le solde à la Caisse, ou choisissez une dette validée ou une sortie évadé.",
            ]);
        }

        if ($type !== AdministrativeExitType::PaidCash && $balanceMinor === 0) {
            throw ValidationException::withMessages([
                'exit_type' => 'Le compte est soldé : cette sortie doit être enregistrée en « payé comptant ». Une dette ne peut pas être créée sans reste à payer.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createDebt(
        Episode $episode,
        array $data,
        string $reason,
        User $actor,
        AdministrativeExitType $type,
        string $amount,
        string $currency,
    ): PatientDebt {
        return PatientDebt::create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->getKey(),
            'debt_number' => $this->numbers->patientDebt(),
            'origin' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
            'comment' => $data['comment'] ?? null,
            'recorded_by' => $actor->getKey(),
            // A validated debt names who will pay and who allowed it; an
            // escape names neither, because nobody committed to anything.
            'responsible_name' => $type === AdministrativeExitType::DebtValidated
                ? ($data['responsible_name'] ?? null) : null,
            'responsible_phone' => $type === AdministrativeExitType::DebtValidated
                ? ($data['responsible_phone'] ?? null) : null,
            'responsible_relationship' => $type === AdministrativeExitType::DebtValidated
                ? ($data['responsible_relationship'] ?? null) : null,
            'due_date' => $type === AdministrativeExitType::DebtValidated
                ? ($data['due_date'] ?? null) : null,
            'authorized_by' => $type === AdministrativeExitType::DebtValidated
                ? $actor->getKey() : null,
            'left_at_estimate' => $type === AdministrativeExitType::Escaped
                ? ($data['left_at_estimate'] ?? null) : null,
            'last_known_service' => $type === AdministrativeExitType::Escaped
                ? ($data['last_known_service'] ?? null) : null,
        ]);
    }
}
