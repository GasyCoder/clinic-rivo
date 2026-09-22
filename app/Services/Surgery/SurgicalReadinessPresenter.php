<?php

namespace App\Services\Surgery;

use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\AnesthesiaClearanceCondition;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use App\Support\SurgicalSafetyChecklistItems;

/**
 * ADR-170 — l'état de préparation opératoire, composé **par le serveur**.
 *
 * L'écran n'a aucune règle à recalculer : il affiche ce que
 * `SurgicalReadinessGate` a décidé. Deux copies de la même règle finiraient
 * par se contredire, et c'est précisément le cas où une divergence se paie au
 * bloc — un bouton actif sur un dossier que le serveur refusera, ou un bouton
 * grisé sans qu'on sache pourquoi.
 *
 * Chaque constat porte son `owner` : l'écran dit « en attente de
 * l'anesthésiste » au lieu de proposer au chirurgien un geste qui n'est pas le
 * sien.
 */
class SurgicalReadinessPresenter
{
    public function __construct(
        private readonly SurgicalReadinessGate $gate,
        private readonly SurgicalCaseActors $actors,
    ) {}

    /** @return array<string, mixed> */
    public function present(SurgicalRequest $case, User $viewer): array
    {
        // Volontairement une instance à part, jamais `$case` lui-même :
        // l'espace Anesthésie sérialise un dossier dont `SurgicalCaseWorkspace`
        // a **retiré** les champs chirurgicaux (compte rendu, notes). Charger
        // ces relations sur l'instance servie les y ferait reparaître.
        $case = SurgicalRequest::query()
            ->with([
                'anesthesiaRecord.clearanceConditions.resolvedBy',
                'anesthesiaRecord.anesthetist',
                'anesthesiaRecord.clearanceDecidedBy',
                'teamMembers.user',
                'safetyChecklists.confirmations.confirmedBy',
                'intervention', 'report', 'blockEntry', 'blockExit',
            ])
            ->findOrFail($case->getKey());

        $blockers = $this->gate->blockersForIncision($case);
        $warnings = $this->gate->warningsForIncision($case);
        $canWriteAnesthesia = $this->actors->canWriteAnesthesia($case, $viewer);

        return [
            'can_start_intervention' => $blockers === [],
            'can_complete' => $this->gate->canComplete($case),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'completion_blockers' => $case->status === SurgicalRequestStatus::InProgress
                ? $this->gate->blockersForCompletion($case)
                : [],
            'steps' => $this->steps($case, $blockers, $warnings),
            'anesthesia' => $this->anesthesia($case, $viewer, $canWriteAnesthesia),
            'checklists' => $this->checklists($case, $viewer),
            'actor' => [
                'can_operate' => $this->actors->canOperate($case, $viewer),
                'can_write_anesthesia' => $canWriteAnesthesia,
                'can_start_intervention' => $viewer->can('startIntervention', $case),
                'can_complete_case' => $viewer->can('complete', $case),
                'can_save_checklist' => $viewer->can('saveChecklist', $case),
                'confirmable_roles' => $this->confirmableRoles($case, $viewer),
            ],
        ];
    }

    /**
     * Les lignes de « État de préparation opératoire » : une par checkpoint,
     * avec son état — jamais une couleur seule, toujours un libellé.
     *
     * @param  array<int, array<string, mixed>>  $blockers
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function steps(SurgicalRequest $case, array $blockers, array $warnings): array
    {
        $blockedBy = array_column($blockers, 'message', 'key');
        $warnedBy = array_column($warnings, 'message', 'key');

        $line = function (string $key, string $label, string $owner, bool $done, ?string $blockingKey = null, ?string $warningKey = null, ?string $detail = null) use ($blockedBy, $warnedBy): array {
            $blocking = $blockingKey !== null
                ? collect($blockedBy)->filter(fn ($m, $k) => str_starts_with($k, $blockingKey))->first()
                : null;
            $warning = $warningKey !== null
                ? collect($warnedBy)->filter(fn ($m, $k) => str_starts_with($k, $warningKey))->first()
                : null;

            return [
                'key' => $key,
                'label' => $label,
                'owner' => $owner,
                'status' => match (true) {
                    $done => 'DONE',
                    $blocking !== null => 'BLOCKING',
                    $warning !== null => 'WARNING',
                    default => 'PENDING',
                },
                'detail' => $detail ?? $blocking ?? $warning,
            ];
        };

        $record = $case->anesthesiaRecord;

        return [
            $line('surgical_preop', 'Feu vert préopératoire — équipe chirurgicale',
                SurgicalReadinessGate::OWNER_SURGERY,
                $case->preoperative_validated_at !== null,
                'surgical_preop'),
            $line('anesthetist', 'Anesthésiste affecté',
                SurgicalReadinessGate::OWNER_SURGERY,
                $this->actors->hasAnesthetist($case),
                'anesthetist_missing'),
            $line('assessment', 'Évaluation pré-anesthésique validée',
                SurgicalReadinessGate::OWNER_ANESTHESIA,
                $record?->assessmentIsValidated() === true,
                'assessment_not_validated'),
            $line('clearance', 'Autorisation anesthésique',
                SurgicalReadinessGate::OWNER_ANESTHESIA,
                $record?->clearance_status->allowsIncision() === true
                    && ! $record->clearanceHasExpired()
                    && $record->openConditions()->isEmpty(),
                'clearance',
                null,
                $record?->clearance_status->label()),
            $this->checklistLine($case, SurgicalChecklistPhase::SignIn, $blockers, $warnings),
            $this->checklistLine($case, SurgicalChecklistPhase::TimeOut, $blockers, $warnings),
            $line('block_entry', 'Entrée au bloc',
                SurgicalReadinessGate::OWNER_BLOCK,
                $case->blockEntry !== null,
                null,
                'block_entry_missing'),
        ];
    }

    /**
     * Un temps de la checklist : ses constats sont plusieurs (points non
     * cochés, confirmations manquantes) et se reconnaissent à la phase qu'ils
     * nomment, jamais à un préfixe approximatif.
     *
     * @param  array<int, array<string, mixed>>  $blockers
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function checklistLine(SurgicalRequest $case, SurgicalChecklistPhase $phase, array $blockers, array $warnings): array
    {
        $slug = strtolower($phase->value);
        $checklist = $case->safetyChecklist($phase);

        $mine = fn (array $issues, string $needle) => collect($issues)
            ->filter(fn (array $issue) => str_contains($issue['key'], $needle))
            ->pluck('message')
            ->all();

        $blocking = $mine($blockers, $slug);
        $warned = $mine($warnings, $phase->value);

        return [
            'key' => $slug,
            'label' => $phase->label(),
            'owner' => SurgicalReadinessGate::OWNER_BLOCK,
            'status' => match (true) {
                $checklist?->isCompleted() === true => 'DONE',
                $blocking !== [] => 'BLOCKING',
                $warned !== [] => 'WARNING',
                default => 'PENDING',
            },
            'detail' => $blocking !== []
                ? implode(' ', $blocking)
                : ($warned !== [] ? implode(' ', $warned) : $phase->moment()),
        ];
    }

    /**
     * Ce que le chirurgien doit voir de l'anesthésie — et rien de plus : il
     * lit la décision, il ne la modifie jamais.
     *
     * @return array<string, mixed>|null
     */
    private function anesthesia(SurgicalRequest $case, User $viewer, bool $canWrite): ?array
    {
        $anesthetist = $case->anesthesiaRecord?->anesthetist
            ?? $case->teamMembers->first(
                fn (SurgicalTeamMember $member) => $member->function === SurgicalTeamFunction::Anesthetist,
            )?->user;

        $record = $case->anesthesiaRecord;

        if ($record === null) {
            return [
                'record_exists' => false,
                'anesthetist' => $anesthetist?->name,
                'can_decide' => false,
                'can_write' => $canWrite,
            ];
        }

        $status = $record->clearance_status;

        return [
            'record_exists' => true,
            'anesthetist' => $anesthetist?->name,
            'assessment_validated_at' => $record->assessment_validated_at?->toIso8601String(),
            'record_validated_at' => $record->validated_at?->toIso8601String(),
            'clearance' => [
                'status' => $status->value,
                'label' => $status->label(),
                'description' => $status->description(),
                'badge' => $status->badgeVariant(),
                'allows_incision' => $status->allowsIncision(),
                'reason' => $record->clearance_reason,
                'decided_by' => $record->clearanceDecidedBy?->name,
                'decided_at' => $record->clearance_decided_at?->toIso8601String(),
                'valid_until' => $record->clearance_valid_until?->toIso8601String(),
                'expired' => $record->clearanceHasExpired(),
            ],
            'conditions' => $record->clearanceConditions
                ->map(fn (AnesthesiaClearanceCondition $row) => [
                    'id' => $row->getKey(),
                    'label' => $row->label,
                    'status' => $row->status->value,
                    'status_label' => $row->status === AnesthesiaClearanceConditionStatus::Open ? 'À lever' : 'Levée',
                    'open' => $row->isOpen(),
                    'resolved_by' => $row->resolvedBy?->name,
                    'resolved_at' => $row->resolved_at?->toIso8601String(),
                    'resolution_notes' => $row->resolution_notes,
                ])->values()->all(),
            'can_decide' => $viewer->can('decideClearance', $record),
            'can_write' => $canWrite,
        ];
    }

    /**
     * Les trois temps, avec leurs points et leurs confirmations. Les items
     * viennent du référentiel relu (`SurgicalSafetyChecklistItems`) : l'écran
     * n'en connaît aucun.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checklists(SurgicalRequest $case, User $viewer): array
    {
        $confirmable = $this->confirmableRoles($case, $viewer);

        return array_map(function (SurgicalChecklistPhase $phase) use ($case, $confirmable): array {
            $checklist = $case->safetyChecklist($phase);
            $checked = $checklist?->checked_items ?? [];

            return [
                'phase' => $phase->value,
                'label' => $phase->label(),
                'moment' => $phase->moment(),
                'completed_at' => $checklist?->completed_at?->toIso8601String(),
                'notes' => $checklist?->notes,
                'items' => array_map(fn (array $item) => [
                    ...$item,
                    'checked' => (bool) ($checked[$item['key']] ?? false),
                ], SurgicalSafetyChecklistItems::for($phase)),
                'confirmations' => array_map(function (SurgicalChecklistRole $role) use ($checklist, $confirmable): array {
                    $confirmation = $checklist?->confirmations
                        ->firstWhere('role', $role);

                    return [
                        'role' => $role->value,
                        'label' => $role->label(),
                        'confirmed_by' => $confirmation?->confirmedBy?->name,
                        'confirmed_at' => $confirmation?->confirmed_at?->toIso8601String(),
                        'can_confirm' => in_array($role->value, $confirmable, true)
                            && $confirmation === null
                            && $checklist?->isCompleted() !== true,
                        'permission' => $role->permission(),
                    ];
                }, $phase->requiredRoles()),
                // SIGN OUT n'a de sens qu'une fois l'intervention ouverte.
                'available' => $phase !== SurgicalChecklistPhase::SignOut || $case->intervention !== null,
            ];
        }, SurgicalChecklistPhase::cases());
    }

    /**
     * Les rôles que **ce** compte peut confirmer sur **ce** dossier. L'écran
     * n'en déduit rien : proposer un bouton que le serveur refusera est le
     * défaut que cette liste supprime.
     *
     * @return array<int, string>
     */
    private function confirmableRoles(SurgicalRequest $case, User $viewer): array
    {
        $roles = [];

        foreach (SurgicalChecklistRole::cases() as $role) {
            if ($viewer->cannot($role->permission())) {
                continue;
            }

            $holds = match ($role) {
                SurgicalChecklistRole::Anesthesia => $this->actors->isAnesthetistOf($case, $viewer),
                SurgicalChecklistRole::Surgeon => $this->actors->isSurgeonOf($case, $viewer)
                    || $this->actors->supervises($viewer),
                SurgicalChecklistRole::Nursing => $this->actors->isTeamMemberOf($case, $viewer)
                    || $this->actors->supervises($viewer),
            };

            if ($holds) {
                $roles[] = $role->value;
            }
        }

        return $roles;
    }
}
