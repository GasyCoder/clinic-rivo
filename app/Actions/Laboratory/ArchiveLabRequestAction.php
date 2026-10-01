<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — ranger une demande terminée, et l'en sortir.
 *
 * Archiver n'efface rien et n'a aucun effet clinique ni financier : la demande
 * quitte la file du laboratoire et se retrouve dans « Archivées ». Seule une
 * demande dont tout est envoyé au médecin se range — ranger du travail à faire
 * le ferait disparaître. Réversible, sans motif (ADR-131, même raisonnement).
 */
class ArchiveLabRequestAction
{
    public const PERMISSION = 'laboratory_orders.archive';

    public function __construct(private readonly Auditor $auditor) {}

    public function archive(LabRequest $request, User $actor): LabRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor): LabRequest {
            $locked = LabRequestGuard::lock($request);

            if ($locked->isLabArchived()) {
                throw ValidationException::withMessages(['request' => 'Cette demande est déjà archivée.']);
            }

            if (! LabRequestGuard::finished($locked)) {
                throw ValidationException::withMessages([
                    'request' => 'Seule une demande dont toutes les analyses sont envoyées au médecin se range : celle-ci a encore du travail en cours.',
                ]);
            }

            $locked->forceFill(['lab_archived_at' => now(), 'lab_archived_by' => $actor->getKey()])->save();
            $this->auditor->record('laboratory.request.archive', entity: $locked, newValues: ['lab_archived_at' => $locked->lab_archived_at?->toIso8601String()], module: 'clinical_flow', actor: $actor);

            return $locked;
        });
    }

    public function unarchive(LabRequest $request, User $actor): LabRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor): LabRequest {
            $locked = LabRequestGuard::lock($request);

            if (! $locked->isLabArchived()) {
                throw ValidationException::withMessages(['request' => 'Cette demande n’est pas archivée.']);
            }

            $old = $locked->lab_archived_at?->toIso8601String();
            $locked->forceFill(['lab_archived_at' => null, 'lab_archived_by' => null])->save();
            $this->auditor->record('laboratory.request.unarchive', entity: $locked, oldValues: ['lab_archived_at' => $old], module: 'clinical_flow', actor: $actor);

            return $locked;
        });
    }

    private function authorize(User $actor): void
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Archiver une demande demande le droit « laboratory_orders.archive ».');
        }
    }
}
