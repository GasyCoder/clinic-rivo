<?php

namespace App\Services\Surgery;

use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Enums\SurgicalRequestStatus;
use App\Models\AnesthesiaClearanceCondition;
use App\Models\SurgicalRequest;
use App\Models\SurgicalSafetyChecklist;
use App\Models\User;
use App\Support\SurgicalSafetyChecklistItems;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — l'autorité unique des deux transitions critiques du bloc :
 * démarrer l'intervention, et clore le dossier.
 *
 * Chirurgie et Anesthésie travaillent **en parallèle** : rien ici n'impose à
 * l'une d'attendre que l'autre ait tout fini. Ce qui est imposé, ce sont deux
 * points de rendez-vous — l'incision et la clôture — où les vérifications de
 * sécurité doivent réellement être faites.
 *
 * Deux natures de constats, et la distinction est la règle centrale :
 *
 * ```text
 * blocker   la transition est refusée, par le serveur, y compris en POST direct
 * warning   l'équipe est prévenue et décide : on ne retient jamais un bloc
 *           opératoire pour un champ facultatif
 * ```
 *
 * Chaque constat porte son `owner` : à qui appartient le geste qui manque. Un
 * chirurgien ne doit pas se voir proposer de remplir une décision d'anesthésie
 * — l'écran affiche « en attente de l'anesthésiste », et c'est cette valeur
 * qui le lui permet.
 *
 * @phpstan-type Issue array{key: string, owner: string, message: string, hint: string|null}
 */
class SurgicalReadinessGate
{
    public const OWNER_SURGERY = 'SURGERY';

    public const OWNER_ANESTHESIA = 'ANESTHESIA';

    public const OWNER_BLOCK = 'BLOCK';

    public function __construct(private readonly SurgicalCaseActors $actors) {}

    // ── Incision ────────────────────────────────────────────────────────

    /**
     * Ce qui empêche l'intervention de démarrer. Liste vide = rien ne s'y oppose.
     *
     * @return array<int, array<string, mixed>>
     */
    public function blockersForIncision(SurgicalRequest $case): array
    {
        $case->loadMissing(['anesthesiaRecord.clearanceConditions', 'teamMembers', 'safetyChecklists.confirmations', 'intervention']);

        $issues = [];

        if ($case->status === SurgicalRequestStatus::Cancelled) {
            return [$this->issue('case_cancelled', self::OWNER_SURGERY,
                'Cette demande a été retirée : aucune intervention ne peut y démarrer.')];
        }

        if ($case->intervention !== null) {
            $issues[] = $this->issue('intervention_exists', self::OWNER_SURGERY,
                'Une intervention est déjà ouverte sur ce dossier.');
        }

        if ($case->status !== SurgicalRequestStatus::PreoperativeValidated) {
            $issues[] = $this->issue('surgical_preop', self::OWNER_SURGERY,
                'Le feu vert préopératoire de l’équipe chirurgicale n’est pas confirmé.',
                'Étape « Préparation » du dossier.');
        }

        array_push($issues, ...$this->anesthesiaBlockers($case));
        array_push($issues, ...$this->checklistBlockers($case, SurgicalChecklistPhase::SignIn));
        array_push($issues, ...$this->checklistBlockers($case, SurgicalChecklistPhase::TimeOut));

        return $issues;
    }

    /** @return array<int, array<string, mixed>> */
    public function warningsForIncision(SurgicalRequest $case): array
    {
        $case->loadMissing(['blockEntry', 'safetyChecklists.confirmations']);

        $issues = [];

        if ($case->blockEntry === null) {
            $issues[] = $this->issue('block_entry_missing', self::OWNER_BLOCK,
                'La fiche d’entrée au bloc n’est pas encore renseignée.',
                'Elle se complète aussi après l’incision : elle ne retient pas l’intervention.');
        }

        foreach ([SurgicalChecklistPhase::SignIn, SurgicalChecklistPhase::TimeOut] as $phase) {
            $checklist = $case->safetyChecklist($phase);

            if ($checklist === null || $checklist->isCompleted()) {
                continue;
            }

            $optional = $this->uncheckedOptionalItems($checklist);

            if ($optional !== []) {
                $issues[] = $this->issue("checklist_optional_{$phase->value}", self::OWNER_BLOCK,
                    $phase->label().' : '.count($optional).' point(s) facultatif(s) non coché(s).',
                    'Facultatif : ces points ne retiennent pas l’incision.');
            }
        }

        return $issues;
    }

    public function canStartIntervention(SurgicalRequest $case): bool
    {
        return $this->blockersForIncision($case) === [];
    }

    /**
     * Refuse le démarrage avec un message exploitable. Appelée **dans** la
     * transaction qui démarre l'intervention, sur un dossier déjà verrouillé :
     * l'écran n'est jamais la seule protection, et un POST direct est refusé.
     */
    public function assertCanStartIntervention(SurgicalRequest $case, User $actor): void
    {
        if (! $this->actors->canOperate($case, $actor)) {
            throw ValidationException::withMessages([
                'intervention' => 'Vous n’êtes pas chirurgien de ce dossier : l’intervention se démarre par l’opérateur prévu ou par la supervision du bloc.',
            ]);
        }

        $blockers = $this->blockersForIncision($case);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'intervention' => $this->sentence(
                    'L’intervention ne peut pas démarrer',
                    $blockers,
                ),
            ]);
        }
    }

    // ── Clôture du dossier ──────────────────────────────────────────────

    /**
     * Ce qui empêche de clore le dossier (IN_PROGRESS → COMPLETED).
     *
     * @return array<int, array<string, mixed>>
     */
    public function blockersForCompletion(SurgicalRequest $case): array
    {
        $case->loadMissing(['intervention', 'report', 'anesthesiaRecord', 'safetyChecklists.confirmations', 'blockExit']);

        if ($case->status !== SurgicalRequestStatus::InProgress) {
            return [$this->issue('not_in_progress', self::OWNER_SURGERY,
                'Seul un dossier au bloc peut être clôturé.')];
        }

        $issues = [];

        if ($case->intervention?->ended_at === null) {
            $issues[] = $this->issue('intervention_open', self::OWNER_SURGERY,
                'L’heure de fin de l’intervention n’est pas renseignée.',
                'Étape « Intervention ».');
        }

        if ($case->report?->validated_at === null) {
            $issues[] = $this->issue('report_not_validated', self::OWNER_SURGERY,
                'Le compte rendu opératoire n’est pas validé.',
                'Étape « Suivi & clôture ».');
        }

        array_push($issues, ...$this->checklistBlockers($case, SurgicalChecklistPhase::SignOut));

        if ($case->blockExit === null) {
            $issues[] = $this->issue('block_exit_missing', self::OWNER_BLOCK,
                'La fiche de sortie du bloc n’est pas renseignée.',
                'Étape « Sortie du bloc ».');
        }

        if ($case->anesthesiaRecord === null) {
            $issues[] = $this->issue('anesthesia_missing_completion', self::OWNER_ANESTHESIA,
                'Aucun dossier d’anesthésie n’a été ouvert pour cette intervention.');
        } elseif ($case->anesthesiaRecord->validated_at === null) {
            $issues[] = $this->issue('anesthesia_not_validated', self::OWNER_ANESTHESIA,
                'Le dossier d’anesthésie n’est pas finalisé par l’anesthésiste.',
                'En attente de l’anesthésiste.');
        }

        return $issues;
    }

    public function canComplete(SurgicalRequest $case): bool
    {
        return $this->blockersForCompletion($case) === [];
    }

    public function assertCanComplete(SurgicalRequest $case, User $actor): void
    {
        if (! $this->actors->canOperate($case, $actor)) {
            throw ValidationException::withMessages([
                'case' => 'Vous n’êtes pas chirurgien de ce dossier : sa clôture revient à l’opérateur ou à la supervision du bloc.',
            ]);
        }

        $blockers = $this->blockersForCompletion($case);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'case' => $this->sentence('Le dossier ne peut pas être clôturé', $blockers),
            ]);
        }
    }

    // ── Détail ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function anesthesiaBlockers(SurgicalRequest $case): array
    {
        $issues = [];
        $record = $case->anesthesiaRecord;

        if (! $this->actors->hasAnesthetist($case)) {
            $issues[] = $this->issue('anesthetist_missing', self::OWNER_SURGERY,
                'Aucun anesthésiste n’est affecté à ce dossier.',
                'Il s’affecte depuis l’équipe de bloc.');
        }

        if ($record === null) {
            $issues[] = $this->issue('anesthesia_record_missing', self::OWNER_ANESTHESIA,
                'Aucun dossier d’anesthésie n’est ouvert.',
                'En attente de l’anesthésiste.');

            return $issues;
        }

        if (! $record->assessmentIsValidated()) {
            $issues[] = $this->issue('assessment_not_validated', self::OWNER_ANESTHESIA,
                'L’évaluation pré-anesthésique n’est pas validée.',
                'En attente de l’anesthésiste.');
        }

        $clearance = $record->clearance_status;

        if (! $clearance->allowsIncision()) {
            $issues[] = $this->issue('clearance_'.strtolower($clearance->value), self::OWNER_ANESTHESIA,
                'Anesthésie — '.$clearance->label().' : '.$clearance->description(),
                $record->clearance_reason ? 'Motif : '.$record->clearance_reason : 'En attente de l’anesthésiste.');
        } elseif ($record->clearanceHasExpired()) {
            $issues[] = $this->issue('clearance_expired', self::OWNER_ANESTHESIA,
                'L’autorisation anesthésique est arrivée à échéance : elle doit être reprononcée.',
                'En attente de l’anesthésiste.');
        }

        $open = $record->openConditions();

        if ($clearance->allowsIncision() && $open->isNotEmpty()) {
            $issues[] = $this->issue('clearance_conditions_open', self::OWNER_ANESTHESIA,
                $open->count() === 1
                    ? 'Une condition de l’autorisation anesthésique reste à lever.'
                    : $open->count().' conditions de l’autorisation anesthésique restent à lever.',
                $open->map(fn (AnesthesiaClearanceCondition $row) => $row->label)->implode(' · '));
        }

        return $issues;
    }

    /** @return array<int, array<string, mixed>> */
    private function checklistBlockers(SurgicalRequest $case, SurgicalChecklistPhase $phase): array
    {
        $checklist = $case->safetyChecklist($phase);

        if ($checklist === null) {
            return [$this->issue('checklist_missing_'.strtolower($phase->value), self::OWNER_BLOCK,
                $phase->label().' : non commencé.',
                $phase->moment().'.')];
        }

        if ($checklist->isCompleted()) {
            return [];
        }

        $missingItems = $checklist->missingRequiredItems();
        $missingRoles = $checklist->missingConfirmations();
        $issues = [];

        if ($missingItems !== []) {
            $issues[] = $this->issue('checklist_items_'.strtolower($phase->value), self::OWNER_BLOCK,
                $phase->label().' : '.count($missingItems).' point(s) obligatoire(s) non coché(s).',
                $phase->moment().'.');
        }

        foreach ($missingRoles as $role) {
            $issues[] = $this->issue(
                'checklist_confirm_'.strtolower($phase->value).'_'.strtolower($role->value),
                $role === SurgicalChecklistRole::Anesthesia ? self::OWNER_ANESTHESIA : self::OWNER_BLOCK,
                $phase->label().' : confirmation manquante — '.$role->label().'.',
                'Chaque métier confirme sa propre part.',
            );
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function uncheckedOptionalItems(SurgicalSafetyChecklist $checklist): array
    {
        $checked = $checklist->checked_items ?? [];
        $required = SurgicalSafetyChecklistItems::requiredKeys($checklist->phase);

        return array_values(array_filter(
            SurgicalSafetyChecklistItems::keys($checklist->phase),
            fn (string $key) => ! in_array($key, $required, true) && ! ($checked[$key] ?? false),
        ));
    }

    private function issue(string $key, string $owner, string $message, ?string $hint = null): array
    {
        return ['key' => $key, 'owner' => $owner, 'message' => $message, 'hint' => $hint];
    }

    /** @param  array<int, array<string, mixed>>  $issues */
    private function sentence(string $prefix, array $issues): string
    {
        return $prefix.' : '.collect($issues)->pluck('message')->implode(' ');
    }
}
