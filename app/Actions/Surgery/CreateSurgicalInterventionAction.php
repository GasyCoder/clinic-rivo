<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalIntervention;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Surgery\SurgicalCaseActors;
use App\Services\Surgery\SurgicalReadinessGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — le démarrage de l'intervention, seule transition où les
 * vérifications de sécurité du bloc deviennent opposables.
 *
 * Avant cette décision, l'Action appelait `startIntervention()` puis créait la
 * ligne : le seul garde-fou était le statut du dossier, et rien ne regardait
 * l'anesthésie. Un dossier sans anesthésiste, sans autorisation et sans
 * TIME OUT pouvait passer au bloc.
 *
 * Désormais, tout se joue dans une transaction, sur un dossier verrouillé, et
 * c'est `SurgicalReadinessGate` qui décide. L'écran n'est jamais la protection :
 * un POST direct rencontre exactement le même refus.
 */
class CreateSurgicalInterventionAction
{
    public function __construct(
        private readonly SurgicalReadinessGate $gate,
        private readonly SurgicalCaseActors $actors,
    ) {}

    /**
     * @param  array{performed_by?: ?int, started_at?: ?string, notes?: ?string}  $data
     */
    public function execute(SurgicalRequest $surgicalRequest, array $data, User $actor): SurgicalIntervention
    {
        return DB::transaction(function () use ($surgicalRequest, $data, $actor): SurgicalIntervention {
            $case = SurgicalRequest::query()
                ->with(['teamMembers', 'anesthesiaRecord.clearanceConditions', 'safetyChecklists.confirmations', 'intervention'])
                ->lockForUpdate()
                ->findOrFail($surgicalRequest->getKey());

            $this->gate->assertCanStartIntervention($case, $actor);

            $operator = $this->resolveOperator($case, $data['performed_by'] ?? null, $actor);

            $case->startIntervention();

            return $case->intervention()->create([
                'performed_by' => $operator->getKey(),
                'started_at' => $data['started_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * L'opérateur est un chirurgien **de ce dossier**. La FormRequest
     * n'acceptait qu'un compte actif : n'importe qui pouvait être inscrit comme
     * ayant opéré. Le contrôle appartient ici, où le dossier est connu.
     */
    private function resolveOperator(SurgicalRequest $case, ?int $performedBy, User $actor): User
    {
        if ($performedBy === null || $performedBy === $actor->getKey()) {
            return $actor;
        }

        $operator = User::query()->find($performedBy);

        if ($operator === null || ! $operator->active || $operator->deactivated_at !== null) {
            throw ValidationException::withMessages([
                'performed_by' => 'Ce compte n’est pas actif.',
            ]);
        }

        if (! $this->actors->canOperate($case, $operator)) {
            throw ValidationException::withMessages([
                'performed_by' => "« {$operator->name} » n’est pas chirurgien de ce dossier : l’opérateur se choisit parmi les chirurgiens programmés.",
            ]);
        }

        return $operator;
    }
}
