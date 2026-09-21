<?php

namespace App\Actions\Hospitalization;

use App\Models\HospitalStay;
use App\Models\HospitalStayNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-162 — la note quotidienne du séjour (S/O/A/P), append-only.
 *
 * Elle remplace la visite de service : trois lignes n'exigent plus d'ouvrir
 * une consultation entière. L'heure est celle du serveur, l'auteur celui qui
 * est connecté — jamais un nom saisi.
 */
class RecordHospitalStayNoteAction
{
    /** @param array{subjective?: ?string, objective?: ?string, assessment?: ?string, plan?: ?string} $data */
    public function execute(HospitalStay $stay, array $data, User $actor): HospitalStayNote
    {
        return DB::transaction(function () use ($stay, $data, $actor): HospitalStayNote {
            $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['subjective' => 'Ce séjour est terminé : le patient n’est plus au lit.']);
            }

            $values = collect(['subjective', 'objective', 'assessment', 'plan'])
                ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($data[$key] ?? '')) ?: null]);

            if ($values->filter()->isEmpty()) {
                throw ValidationException::withMessages(['subjective' => 'Écrivez au moins une des quatre rubriques de la note.']);
            }

            return $locked->notes()->create([
                ...$values->all(),
                'written_at' => now(),
                'written_by' => $actor->getKey(),
            ]);
        });
    }
}
