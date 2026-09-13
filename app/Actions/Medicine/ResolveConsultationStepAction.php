<?php

namespace App\Actions\Medicine;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationStep as StepKey;
use App\Enums\ConsultationStepStatus;
use App\Models\Consultation;
use App\Models\ConsultationStep;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the doctor's decision about one step: carried out (COMPLETED) or
 * declared unnecessary for this patient (SKIPPED).
 *
 * Never called as a side effect of saving content — saving leaves the step
 * IN_PROGRESS. A step becomes COMPLETED only when the doctor validates it,
 * and only if its own minimum is actually met, so the stepper can never
 * show as done something that was merely opened.
 */
class ResolveConsultationStepAction
{
    public function __construct(private readonly ConsultationWorkflow $workflow) {}

    public function complete(Consultation $consultation, StepKey $step, User $actor): ConsultationStep
    {
        return DB::transaction(function () use ($consultation, $step, $actor): ConsultationStep {
            $locked = $this->lockEditable($consultation);
            $blocker = $this->workflow->blockerFor($locked, $step);

            if ($blocker !== null) {
                throw ValidationException::withMessages(['step' => $blocker]);
            }

            return $this->write($locked, $step, [
                'status' => ConsultationStepStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'skip_reason' => null,
            ]);
        });
    }

    public function skip(Consultation $consultation, StepKey $step, ?string $reason, User $actor): ConsultationStep
    {
        return DB::transaction(function () use ($consultation, $step, $reason, $actor): ConsultationStep {
            $locked = $this->lockEditable($consultation);

            if (! $step->isSkippable()) {
                throw ValidationException::withMessages([
                    'step' => 'Cette étape ne peut pas être déclarée non nécessaire.',
                ]);
            }

            return $this->write($locked, $step, [
                'status' => ConsultationStepStatus::Skipped,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'skip_reason' => filled($reason) ? trim($reason) : null,
            ]);
        });
    }

    /** Saving content marks progress, never completion. */
    public function markInProgress(Consultation $consultation, StepKey $step): void
    {
        $existing = $consultation->steps()->where('step', $step->value)->first();

        // A step already resolved keeps its resolution: re-saving its
        // content must not silently demote a validated step, and the
        // doctor re-validates explicitly if the change matters.
        if ($existing?->status->isResolved()) {
            return;
        }

        $consultation->steps()->updateOrCreate(
            ['step' => $step->value],
            ['status' => ConsultationStepStatus::InProgress],
        );
    }

    private function write(Consultation $consultation, StepKey $step, array $attributes): ConsultationStep
    {
        $consultation->steps()->updateOrCreate(['step' => $step->value], $attributes);

        // The encounter is under way as soon as one step is resolved: a
        // consultation that still reads DRAFT while a step is validated
        // would misreport its own state.
        if ($consultation->status === ConsultationStatus::Draft) {
            $consultation->update(['status' => ConsultationStatus::InProgress]);
        }

        return $consultation->steps()->where('step', $step->value)->sole();
    }

    private function lockEditable(Consultation $consultation): Consultation
    {
        $locked = Consultation::query()
            ->with(['episode.serviceRequests'])
            ->lockForUpdate()
            ->findOrFail($consultation->getKey());

        if (! $locked->isEditable()) {
            throw ValidationException::withMessages([
                'step' => 'Cette consultation est clôturée : les étapes ne sont plus modifiables.',
            ]);
        }

        return $locked;
    }
}
