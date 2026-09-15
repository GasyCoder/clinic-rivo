<?php

namespace App\Actions\Medicine;

use App\Models\ClinicalExamination;
use App\Models\Consultation;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le médecin déclare s'il conclut maintenant, ou s'il attend (ADR-095).
 *
 * « Pas maintenant » ne débloque rien : la clôture continue d'exiger un
 * diagnostic pour toute vraie consultation (CDC §33.1, ADR-081). Ce que la
 * réponse change, c'est ce que le dossier *dit* — « diagnostic différé par
 * le médecin » au lieu de « aucun diagnostic enregistré ». C'est la même
 * distinction que le reste du dossier tient partout ailleurs : une absence
 * n'est jamais une décision (ADR-076, ADR-077).
 *
 * La réponse est écrite sur `clinical_examinations`, là où elle vivait déjà.
 * La ligne peut être créée pour un passage qui n'a pas d'examen clinique :
 * elle ne porte alors ni état général, ni conscience, ni appareil, et
 * `ConsultationWorkflow::hasClinicalExamination()` — qui teste du contenu
 * réel, jamais l'existence de la ligne — continue donc de répondre « aucun
 * examen ». Aucun examen n'est inventé.
 */
class DecideDiagnosisTimingAction
{
    public function __construct(private readonly ConsultationWorkflow $workflow) {}

    /**
     * @throws ValidationException
     */
    public function execute(Consultation $consultation, bool $ready, User $actor): void
    {
        DB::transaction(function () use ($consultation, $ready, $actor): void {
            /** @var Consultation $locked */
            $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages([
                    'ready' => 'Cette consultation est clôturée : sa conclusion ne peut plus être modifiée.',
                ]);
            }

            // Répondre « Oui » sans avoir rien enregistré affirmerait une
            // conclusion qui n'existe pas. Même refus qu'à l'ADR-080, qui
            // posait déjà la question ailleurs.
            if ($ready && ! $this->workflow->hasActiveDiagnosisFor($locked)) {
                throw ValidationException::withMessages([
                    'ready' => 'Enregistrez le diagnostic ci-dessous avant de répondre « Oui », ou choisissez « Pas maintenant ».',
                ]);
            }

            ClinicalExamination::query()->updateOrCreate(
                ['consultation_id' => $locked->getKey()],
                [
                    'diagnosis_ready' => $ready,
                    'examined_by' => $actor->getKey(),
                    'examined_at' => now(),
                ],
            );
        });
    }
}
