<?php

namespace App\Actions\Medicine;

use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ranger une demande d'examen lue, ou la ressortir des archives (ADR-131).
 *
 * Un drapeau daté et signé : rien n'est supprimé, aucun résultat ni aucune
 * facturation ne bouge. On ne range que ce qui est **rendu** — archiver une
 * demande encore en attente ferait disparaître du travail à faire, et un vide
 * muet se lit « rien à faire ».
 */
class ArchiveParaclinicalRequestAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param  'lab'|'imaging'  $kind */
    public function execute(string $kind, string $uuid, bool $archive, User $actor): LabRequest|ImagingRequest
    {
        return DB::transaction(function () use ($kind, $uuid, $archive, $actor): LabRequest|ImagingRequest {
            $model = $kind === 'lab' ? LabRequest::class : ImagingRequest::class;
            $request = $model::query()->with('items')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($archive) {
                if ($request->displayStatus() !== 'COMPLETED') {
                    throw ValidationException::withMessages([
                        'request' => 'Seule une demande dont tous les résultats sont rendus peut être archivée.',
                    ]);
                }

                if ($request->archived_at !== null) {
                    throw ValidationException::withMessages(['request' => 'Cette demande est déjà archivée.']);
                }
            } elseif ($request->archived_at === null) {
                throw ValidationException::withMessages(['request' => 'Cette demande n’est pas archivée.']);
            }

            $before = ['archived_at' => $request->archived_at?->toIso8601String()];

            $request->forceFill([
                'archived_at' => $archive ? now() : null,
                'archived_by' => $archive ? $actor->getKey() : null,
            ])->save();

            $this->auditor->record(
                $archive ? 'paraclinical_request.archive' : 'paraclinical_request.unarchive',
                entity: $request,
                oldValues: $before,
                newValues: ['archived_at' => $request->archived_at?->toIso8601String()],
            );

            return $request;
        });
    }
}
