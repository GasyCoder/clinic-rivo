<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalRequest;
use App\Models\SurgicalSafetyChecklist;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;
use App\Support\SurgicalSafetyChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — cocher les points d'un temps de la checklist, et le confirmer pour
 * son propre rôle.
 *
 * Les deux gestes sont dans la même Action parce qu'ils sont le même moment :
 * on coche ce qu'on vient de vérifier, puis on signe. Mais ils restent
 * distincts dans les données — les items sont partagés, la confirmation est
 * nominative et par rôle.
 *
 * **On ne confirme que pour soi.** Un chirurgien ne confirme pas la part de
 * l'anesthésiste : c'est précisément ce qu'une checklist de sécurité existe
 * pour empêcher. Le droit exigé suit le rôle (`SurgicalChecklistRole::permission`),
 * et l'acteur doit tenir ce rôle sur ce dossier.
 */
class SaveSurgicalChecklistAction
{
    public function __construct(private readonly SurgicalCaseActors $actors) {}

    /**
     * @param  array{items?: array<string, mixed>, notes?: ?string, confirm_as?: ?string}  $data
     */
    public function execute(
        SurgicalRequest $surgicalRequest,
        SurgicalChecklistPhase $phase,
        array $data,
        User $actor,
    ): SurgicalSafetyChecklist {
        return DB::transaction(function () use ($surgicalRequest, $phase, $data, $actor): SurgicalSafetyChecklist {
            $case = SurgicalRequest::query()
                ->with(['teamMembers', 'anesthesiaRecord', 'intervention'])
                ->lockForUpdate()
                ->findOrFail($surgicalRequest->getKey());

            $this->assertPhaseIsOpen($case, $phase);

            $checklist = $case->safetyChecklists()
                ->where('phase', $phase->value)
                ->lockForUpdate()
                ->first()
                ?? $case->safetyChecklists()->make([
                    'phase' => $phase,
                    'checked_items' => [],
                    'created_by' => $actor->getKey(),
                ]);

            if ($checklist->isCompleted() && array_key_exists('items', $data)) {
                throw ValidationException::withMessages([
                    'checklist' => $phase->label().' est confirmé par l’équipe : ses points ne se modifient plus.',
                ]);
            }

            if (array_key_exists('items', $data)) {
                // Omettre un item ne le décoche pas (ADR-074) : seule une valeur
                // réellement envoyée change son état.
                $checklist->checked_items = array_merge(
                    $checklist->checked_items ?? [],
                    $this->cleanItems($phase, $data['items'] ?? []),
                );
            }

            if (array_key_exists('notes', $data)) {
                $checklist->notes = filled($data['notes']) ? trim((string) $data['notes']) : null;
            }

            $checklist->updated_by = $actor->getKey();
            $checklist->save();

            if (filled($data['confirm_as'] ?? null)) {
                $this->confirm($checklist, SurgicalChecklistRole::from($data['confirm_as']), $case, $actor);
            }

            $checklist->load('confirmations.confirmedBy');
            $checklist->refreshCompletion();

            return $checklist;
        });
    }

    private function confirm(
        SurgicalSafetyChecklist $checklist,
        SurgicalChecklistRole $role,
        SurgicalRequest $case,
        User $actor,
    ): void {
        if (! in_array($role, $checklist->phase->requiredRoles(), true)) {
            throw ValidationException::withMessages([
                'confirm_as' => $checklist->phase->label().' ne demande pas de confirmation '.$role->label().'.',
            ]);
        }

        if ($actor->cannot($role->permission())) {
            throw ValidationException::withMessages([
                'confirm_as' => 'Confirmer pour '.$role->label().' demande le droit « '.$role->permission().' ».',
            ]);
        }

        if (! $this->mayConfirmFor($role, $case, $actor)) {
            throw ValidationException::withMessages([
                'confirm_as' => 'Vous ne tenez pas le rôle '.$role->label().' sur ce dossier : chaque métier confirme sa propre part.',
            ]);
        }

        // Idempotent : un double clic ne crée pas deux signatures — la
        // contrainte d'unicité le garantit aussi en base.
        $checklist->confirmations()->firstOrCreate(
            ['role' => $role->value],
            ['confirmed_by' => $actor->getKey(), 'confirmed_at' => now()],
        );
    }

    private function mayConfirmFor(SurgicalChecklistRole $role, SurgicalRequest $case, User $actor): bool
    {
        if ($this->actors->supervises($actor) && $role !== SurgicalChecklistRole::Anesthesia) {
            return true;
        }

        return match ($role) {
            SurgicalChecklistRole::Anesthesia => $this->actors->isAnesthetistOf($case, $actor),
            SurgicalChecklistRole::Surgeon => $this->actors->isSurgeonOf($case, $actor),
            // L'équipe de salle n'a pas une fonction unique (infirmier de bloc
            // ou paramédical) : appartenir à l'équipe du dossier suffit.
            SurgicalChecklistRole::Nursing => $this->actors->isTeamMemberOf($case, $actor)
                || $this->actors->supervises($actor),
        };
    }

    private function assertPhaseIsOpen(SurgicalRequest $case, SurgicalChecklistPhase $phase): void
    {
        if ($case->status === SurgicalRequestStatus::Cancelled) {
            throw ValidationException::withMessages([
                'checklist' => 'Cette demande de chirurgie est annulée.',
            ]);
        }

        if (in_array($case->status, [SurgicalRequestStatus::Completed, SurgicalRequestStatus::Discharged], true)) {
            throw ValidationException::withMessages([
                'checklist' => 'Ce dossier est clôturé : sa checklist ne se modifie plus.',
            ]);
        }

        // SIGN OUT appartient à la fin de l'intervention : le remplir avant
        // l'incision reviendrait à attester ce qui n'a pas encore eu lieu.
        if ($phase === SurgicalChecklistPhase::SignOut && $case->intervention === null) {
            throw ValidationException::withMessages([
                'checklist' => 'Le SIGN OUT se renseigne une fois l’intervention démarrée.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, bool>
     */
    private function cleanItems(SurgicalChecklistPhase $phase, array $items): array
    {
        $known = SurgicalSafetyChecklistItems::keys($phase);
        $clean = [];

        foreach ($known as $key) {
            if (array_key_exists($key, $items)) {
                $clean[$key] = (bool) $items[$key];
            }
        }

        return $clean;
    }
}
