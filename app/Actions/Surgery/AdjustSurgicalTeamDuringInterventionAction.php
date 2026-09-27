<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Surgery\SurgeonRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Amendements de l'ADR-168 — corriger la programmation une fois au bloc.
 *
 * Avant le démarrage, la programmation passe par ScheduleSurgicalRequestAction
 * (planning RH vérifié). Au bloc, chaque fait se corrige encore, un par un :
 *
 *   la date programmée    (l'heure réelle reste le « Début » de l'intervention)
 *   le chirurgien principal
 *   les aides             un chirurgien arrive, ou n'est finalement pas venu
 *   l'opérateur réel      choisi parmi le principal et les aides
 *
 * Un champ omis reste tel quel (ADR-074). Motif obligatoire, audit avec
 * l'ancienne et la nouvelle valeur. Le planning RH n'est pas revérifié : le
 * patient est déjà sur la table. Le profil Chirurgien, lui, l'est toujours.
 */
class AdjustSurgicalTeamDuringInterventionAction
{
    public function __construct(
        private readonly ScheduleSurgicalRequestAction $schedule,
        private readonly SurgeonRoster $roster,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  Collection<int, User>|null  $assistants  null : aides inchangés
     */
    public function execute(
        SurgicalRequest $surgicalRequest,
        ?Collection $assistants,
        ?int $performedBy,
        string $reason,
        User $actor,
        ?User $principal = null,
        ?string $scheduledAt = null,
    ): SurgicalRequest {
        DB::transaction(function () use ($surgicalRequest, $assistants, $performedBy, $reason, $actor, $principal, $scheduledAt): void {
            $case = SurgicalRequest::query()
                ->with(['surgeon.professionalProfile', 'intervention.performedBy', 'teamMembers.user'])
                ->lockForUpdate()
                ->findOrFail($surgicalRequest->getKey());

            if ($case->status !== SurgicalRequestStatus::InProgress || $case->intervention === null) {
                throw ValidationException::withMessages([
                    'team' => 'Cette correction se fait seulement pendant l’intervention (dossier « Au bloc »). Avant, modifiez la programmation.',
                ]);
            }

            $newPrincipal = $principal ?? $case->surgeon;
            if ($newPrincipal === null) {
                throw ValidationException::withMessages(['surgeon_id' => 'Aucun chirurgien principal n’est programmé.']);
            }
            if ($principal !== null && ! $this->roster->isSurgeon($principal)) {
                throw ValidationException::withMessages(['surgeon_id' => "« {$principal->name} » ne porte pas le profil Chirurgien."]);
            }

            $currentAssistants = $case->teamMembers
                ->where('function', SurgicalTeamFunction::Surgeon)
                ->map(fn ($member) => $member->user)
                ->filter()
                ->values();

            $newAssistants = $assistants ?? $currentAssistants;

            // Changer de principal ne réécrit pas qui a opéré : l'ancien principal,
            // s'il est l'opérateur enregistré, reste dans l'équipe comme aide.
            $formerPrincipal = $case->surgeon;
            $operatorNow = $case->intervention->performedBy;
            if ($assistants === null && $principal !== null && $formerPrincipal !== null
                && ! $formerPrincipal->is($principal) && $operatorNow?->is($formerPrincipal)) {
                $newAssistants = $newAssistants->push($formerPrincipal);
            }

            // Un nouveau principal qui était aide quitte les aides : il est déjà dans l'équipe.
            $newAssistants = $newAssistants
                ->reject(fn (User $user) => $user->is($newPrincipal))
                ->unique(fn (User $user) => $user->getKey())
                ->values();

            if ($assistants !== null) {
                $this->schedule->guardSelection($newPrincipal, $newAssistants);
                foreach ($newAssistants as $index => $assistant) {
                    if (! $this->roster->isSurgeon($assistant)) {
                        throw ValidationException::withMessages([
                            "assistant_surgeon_ids.{$index}" => "« {$assistant->name} » ne porte pas le profil Chirurgien.",
                        ]);
                    }
                }
            }

            $operator = $this->resolveOperator($newPrincipal, $newAssistants, $performedBy, $case->intervention->performedBy);

            $before = $this->snapshot($case);

            if ($principal !== null && (int) $case->surgeon_id !== (int) $principal->getKey()) {
                $case->forceFill(['surgeon_id' => $principal->getKey()]);
            }
            if ($scheduledAt !== null) {
                $case->forceFill(['scheduled_at' => CarbonImmutable::parse($scheduledAt)]);
            }
            if ($case->isDirty()) {
                $case->save();
            }

            if ($assistants !== null || $principal !== null) {
                $this->schedule->syncAssistants($case, $newPrincipal, $newAssistants);
            }

            if ($operator !== null && (int) $case->intervention->performed_by !== (int) $operator->getKey()) {
                $case->intervention->forceFill(['performed_by' => $operator->getKey()])->save();
            }

            $after = $this->snapshot($case->refresh()->load(['surgeon', 'intervention.performedBy', 'teamMembers.user']));

            if ($before === $after) {
                throw ValidationException::withMessages(['team' => 'Rien n’a changé.']);
            }

            $this->auditor->record(
                'surgery.team.adjust',
                entity: $case,
                newValues: $after,
                oldValues: $before,
                reason: $reason,
                module: 'surgery',
                actor: $actor,
            );
        });

        return $surgicalRequest->refresh();
    }

    /** @param Collection<int, User> $assistants */
    private function resolveOperator(User $principal, Collection $assistants, ?int $performedBy, ?User $current): ?User
    {
        $team = collect([$principal])->merge($assistants);

        if ($performedBy === null) {
            // Omettre l'opérateur le laisse tel quel — sauf s'il ne fait plus partie de l'équipe.
            if ($current !== null && ! $team->contains(fn (User $user) => $user->is($current))) {
                throw ValidationException::withMessages([
                    'performed_by' => "« {$current->name} » est l’opérateur et ne ferait plus partie de l’équipe : désignez d’abord un autre opérateur.",
                ]);
            }

            return null;
        }

        $candidate = $team->first(fn (User $user) => (int) $user->getKey() === $performedBy);

        if ($candidate === null) {
            throw ValidationException::withMessages([
                'performed_by' => 'L’opérateur se choisit parmi le chirurgien principal et les aides de l’intervention.',
            ]);
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function snapshot(SurgicalRequest $case): array
    {
        return [
            'scheduled_at' => $case->scheduled_at?->toIso8601String(),
            'principal' => $case->surgeon?->name,
            'operator' => $case->intervention?->performedBy?->name,
            'assistants' => $case->teamMembers
                ->where('function', SurgicalTeamFunction::Surgeon)
                ->map(fn ($member) => $member->user?->name)
                ->filter()->sort()->values()->all(),
        ];
    }
}
