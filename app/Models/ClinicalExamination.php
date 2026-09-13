<?php

namespace App\Models;

use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Enums\ConsciousnessStatus;
use App\Enums\GeneralCondition;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The structured part of a Médecine clinical examination: general condition,
 * consciousness, and one status per body system.
 *
 * Carries no vital sign. Blood pressure, heart rate, SpO2, temperature,
 * weight, height and BMI belong to the Soins record of the same episode and
 * reach the doctor read-only (ADR-054) — recording them again here would
 * produce a second version of a measurement nobody took twice.
 *
 * The doctor's free prose stays in `consultations.clinical_exam`, shown as
 * "Notes cliniques complémentaires": one home for that text, not two.
 */
#[Fillable([
    'consultation_id', 'general_condition', 'consciousness_status',
    'consciousness_details', 'general_observation', 'complementary_exams_required', 'diagnosis_ready',
    'examined_by', 'examined_at',
])]
class ClinicalExamination extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'general_condition' => GeneralCondition::class,
            'consciousness_status' => ConsciousnessStatus::class,
            'examined_at' => 'datetime',
            // Nullable on purpose: null is "not decided yet", never "no".
            'complementary_exams_required' => 'boolean',
            'diagnosis_ready' => 'boolean',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examined_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ClinicalExaminationFinding::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Every system with its real status, missing rows reading NOT_EXAMINED.
     *
     * This is the single place that decides what an absent row means, and it
     * never means normal: a system nobody looked at is reported as such.
     *
     * @return Collection<int, array{system: ClinicalExamSystem, status: ClinicalSystemStatus, findings: ?string}>
     */
    public function systems(): Collection
    {
        $recorded = $this->relationLoaded('findings')
            ? $this->findings
            : $this->findings()->get();
        $bySystem = $recorded->keyBy(fn ($row) => $row->system_code->value);

        return collect(ClinicalExamSystem::cases())->map(function (ClinicalExamSystem $system) use ($bySystem): array {
            $row = $bySystem->get($system->value);

            return [
                'system' => $system,
                'status' => $row?->status ?? ClinicalSystemStatus::NotExamined,
                'findings' => $row?->findings,
            ];
        });
    }

    /** Whether the doctor actually examined at least one system. */
    public function hasExaminedSystem(): bool
    {
        return $this->findings()
            ->whereIn('status', [
                ClinicalSystemStatus::Normal->value,
                ClinicalSystemStatus::Abnormal->value,
            ])
            ->exists();
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
