<?php

namespace App\Actions\Medicine;

use App\Models\ClinicalExamination;
use App\Models\Consultation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Billing\ParaclinicalBillingRelease;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records the doctor's answer to "are complementary exams needed?" and keeps
 * the outstanding requests consistent with it.
 *
 * Answering "no" after having ordered something is legitimate — a doctor may
 * reconsider — but it must never make a request disappear. Requests that have
 * produced nothing are CANCELLED, with author, date and reason; requests that
 * already carry a result are never touched, and the change is refused instead
 * (ADR-010).
 */
class DecideComplementaryExamsAction
{
    public function __construct(
        private readonly ParaclinicalBillingRelease $billingRelease,
    ) {}

    /**
     * Records the answer on the examination — the decision is a clinical
     * finding of this encounter, wherever the screen asks for it.
     *
     * The examination row may not exist yet (a doctor can reach Paraclinique
     * before documenting the examination), so it is created with the actor
     * and the moment, and nothing else is assumed.
     */
    public function record(Consultation $consultation, bool $required, User $actor): void
    {
        ClinicalExamination::query()->updateOrCreate(
            ['consultation_id' => $consultation->getKey()],
            [
                'complementary_exams_required' => $required,
                'examined_by' => $actor->getKey(),
                'examined_at' => now(),
            ],
        );
    }

    /**
     * @return array<int, string> the requests cancelled, for the flash message
     */
    public function withdrawOutstandingRequests(
        Consultation $consultation,
        User $actor,
        ?string $reason = null,
    ): array {
        $lab = $this->activeLabRequests($consultation);
        $imaging = $this->activeImagingRequests($consultation);

        // On compte, sans fabriquer de libellés intermédiaires.
        //
        // La version précédente construisait deux collections de chaînes puis
        // les fusionnait. `Eloquent\Collection::map()` ne redescend en
        // collection de base *que si son résultat n'est pas vide* : aucune
        // analyse résultée laissait donc une Eloquent\Collection vide, dont
        // le `merge()` appelle `getKey()` sur chaque élément ajouté — sur des
        // chaînes. Répondre « Non » sur un passage n'ayant que de l'imagerie
        // résultée produisait alors une erreur 500, précisément là où le
        // serveur devait expliquer un refus.
        $resultedCount = $lab->filter(fn (LabRequest $request): bool => $request->hasAnyResult())->count()
            + $imaging->filter(fn (ImagingRequest $request): bool => $request->hasAnyResult())->count();

        // A result is an act that happened. Withdrawing the request that
        // produced it would erase clinical history, so the answer is refused
        // and the doctor is told why rather than losing data.
        if ($resultedCount > 0) {
            throw ValidationException::withMessages([
                'required' => sprintf(
                    'Impossible de déclarer « aucun examen nécessaire » : %d demande(s) ont déjà un résultat. Elles font partie du dossier et ne peuvent pas être retirées.',
                    $resultedCount,
                ),
            ]);
        }

        $withdrawn = [];

        foreach ($lab as $request) {
            $request->update([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $this->reason($reason),
            ]);
            $this->billingRelease->release($request, $actor);
            $withdrawn[] = 'Analyses du '.$request->requested_at?->format('d/m/Y H:i');
        }

        foreach ($imaging as $request) {
            $request->update([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $this->reason($reason),
            ]);
            $this->billingRelease->release($request, $actor);
            $withdrawn[] = 'Imagerie du '.$request->requested_at?->format('d/m/Y H:i');
        }

        return $withdrawn;
    }

    /** Whether anything would be withdrawn — used to ask before acting. */
    public function hasOutstandingRequests(Consultation $consultation): bool
    {
        return $this->activeLabRequests($consultation)->isNotEmpty()
            || $this->activeImagingRequests($consultation)->isNotEmpty();
    }

    /** @return Collection<int, LabRequest> */
    private function activeLabRequests(Consultation $consultation): Collection
    {
        return $consultation->labRequests()->with('items')->whereNull('cancelled_at')->get();
    }

    /** @return Collection<int, ImagingRequest> */
    private function activeImagingRequests(Consultation $consultation): Collection
    {
        return $consultation->imagingRequests()->with('items')->whereNull('cancelled_at')->get();
    }

    private function reason(?string $reason): string
    {
        $trimmed = trim((string) $reason);

        return $trimmed !== ''
            ? $trimmed
            : 'Le médecin a déclaré qu’aucun examen complémentaire n’était nécessaire.';
    }
}
