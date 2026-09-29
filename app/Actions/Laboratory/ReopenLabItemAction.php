<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-216, amendement du 2026-09-29 — rouvrir la saisie d'une analyse terminée
 * qui n'est pas encore envoyée : personne hors du laboratoire ne l'a lue, un
 * motif n'apporte rien. Une analyse envoyée, elle, se renvoie à refaire avec un
 * motif (`ReturnLabItemAction`) : le médecin l'a reçue.
 */
class ReopenLabItemAction
{
    public const PERMISSION = 'laboratory_results.create';

    public function __construct(private readonly Auditor $auditor) {}

    public function execute(LabRequestItem $item, User $actor): LabRequestItem
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Rouvrir la saisie demande le droit « '.self::PERMISSION.' ».');
        }

        return DB::transaction(function () use ($item, $actor): LabRequestItem {
            $locked = LabItemGuard::lock($item);

            if ($locked->currentStatus() !== LabItemStatus::Completed) {
                throw ValidationException::withMessages(['item' => $locked->currentStatus() === LabItemStatus::Validated
                    ? 'Cette analyse est envoyée au médecin : renvoyez-la à refaire, avec un motif.'
                    : 'Cette analyse n’est pas terminée : sa saisie est déjà ouverte.']);
            }

            // Reprise après un envoi : elle redevient « à refaire », avec son motif d'origine ;
            // la valeur que le médecin a lue n'est pas touchée.
            $delivered = $locked->isDelivered();
            $locked->update([
                'status' => $locked->returned_at !== null ? LabItemStatus::ToRedo : LabItemStatus::InProgress,
                ...($delivered ? [] : ['resulted_at' => null, 'resulted_by' => null, 'result_value' => null]),
            ]);

            $this->auditor->record(
                'laboratory.item.reopen',
                $locked,
                ['status' => $locked->status instanceof LabItemStatus ? $locked->status->value : $locked->status],
                ['status' => LabItemStatus::Completed->value],
                module: 'laboratory',
                actor: $actor,
            );

            return $locked->fresh();
        });
    }
}
