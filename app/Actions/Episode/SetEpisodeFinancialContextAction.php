<?php

namespace App\Actions\Episode;

use App\Enums\EpisodeFinancialMode;
use App\Enums\MutualBeneficiaryType;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\EpisodeMutualCoverage;
use App\Models\EpisodeStaffCoverage;
use App\Models\MutualOrganization;
use App\Models\PatientStaffLink;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for an Episode financial context.
 *
 * Patient.patient_type, PatientMutualCoverage and PatientStaffLink are not
 * implicit financial decisions. The staff link is consulted only to prove
 * that the selected Employee is the same permanent person as the Patient.
 */
class SetEpisodeFinancialContextAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function execute(
        Episode $episode,
        EpisodeFinancialMode $mode,
        array $context,
        User $actor,
    ): Episode {
        if ($actor->cannot('episodes.create') && $actor->cannot('episodes.update')) {
            throw new AuthorizationException('Vous ne pouvez pas configurer le contexte financier de ce passage.');
        }

        return DB::transaction(function () use ($episode, $mode, $context, $actor): Episode {
            $episode = Episode::query()
                ->with(['mutualCoverage', 'staffCoverage.employee'])
                ->lockForUpdate()
                ->findOrFail($episode->getKey());

            $oldContext = $this->snapshot($episode);
            $newContext = $this->validatedContext($episode, $mode, $context, $actor);

            if ($this->isSameContext($oldContext, $newContext)) {
                return $episode;
            }

            if ($episode->financial_context_completed_at !== null && $actor->cannot('episodes.update')) {
                throw new AuthorizationException('Vous ne pouvez pas remplacer le contexte financier de ce passage.');
            }

            if ($mode !== EpisodeFinancialMode::Mutual
                && $episode->mutualCoverage?->attachments()->exists()) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'Le contexte financier possède des justificatifs. Sa correction nécessite une procédure dédiée.',
                ]);
            }

            if ($episode->billableItems()->exists() || $episode->invoices()->exists()) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'Le contexte financier ne peut plus être remplacé après la création d’une prestation facturable ou d’une facture.',
                ]);
            }

            $this->persistCoverage($episode, $mode, $newContext, $actor);

            $episode->forceFill([
                'financial_mode' => $mode,
                'financial_context_completed_at' => now(),
                'financial_context_completed_by' => $actor->getKey(),
            ])->save();

            $episode->load(['mutualCoverage', 'staffCoverage.employee']);

            $this->auditor->record(
                'financial_context.set',
                entity: $episode,
                oldValues: $oldContext,
                newValues: $this->snapshot($episode),
                module: 'reception',
                actor: $actor,
            );

            return $episode;
        });
    }

    /** @return array<string, mixed> */
    private function validatedContext(
        Episode $episode,
        EpisodeFinancialMode $mode,
        array $context,
        User $actor,
    ): array {
        if ($mode === EpisodeFinancialMode::Self) {
            return ['financial_mode' => $mode->value];
        }

        if ($mode === EpisodeFinancialMode::Mutual) {
            if ($actor->cannot('mutual_organizations.view')) {
                throw new AuthorizationException('Vous ne pouvez pas utiliser le référentiel des mutuelles.');
            }

            $validated = validator($context, [
                'mutual_organization_uuid' => ['required', 'uuid'],
                'employer_name' => ['required', 'string', 'max:255'],
                'beneficiary_type' => ['required', new Enum(MutualBeneficiaryType::class)],
                'membership_number' => ['required', 'string', 'max:100'],
            ])->validate();

            $organization = MutualOrganization::query()
                ->where('uuid', $validated['mutual_organization_uuid'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $organization) {
                throw ValidationException::withMessages([
                    'mutual_organization_uuid' => 'La mutuelle sélectionnée est indisponible ou archivée.',
                ]);
            }

            return [
                'financial_mode' => $mode->value,
                'mutual_organization_id' => $organization->getKey(),
                'mutual_organization_uuid' => $organization->uuid,
                'mutual_organization_name' => $organization->name,
                'coverage_rate' => $organization->coverage_rate,
                'employer_name' => str($validated['employer_name'])->squish()->toString(),
                'beneficiary_type' => $validated['beneficiary_type'],
                'membership_number' => str($validated['membership_number'])->squish()->toString(),
            ];
        }

        if ($actor->cannot('employees.patient_lookup')) {
            throw new AuthorizationException('Vous ne pouvez pas consulter le référentiel du Personnel.');
        }

        $validated = validator($context, [
            'employee_uuid' => ['required', 'uuid'],
        ])->validate();

        $employee = Employee::query()
            ->where('uuid', $validated['employee_uuid'])
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_uuid' => 'Le dossier Employé sélectionné est indisponible ou archivé.',
            ]);
        }

        $identityLinkExists = PatientStaffLink::query()
            ->active()
            ->where('patient_id', $episode->patient_id)
            ->where('employee_id', $employee->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $identityLinkExists) {
            throw ValidationException::withMessages([
                'employee_uuid' => 'Cet employé n’est pas relié à l’identité permanente de ce patient.',
            ]);
        }

        return [
            'financial_mode' => $mode->value,
            'employee_id' => $employee->getKey(),
            'employee_uuid' => $employee->uuid,
        ];
    }

    /** @param array<string, mixed> $context */
    private function persistCoverage(
        Episode $episode,
        EpisodeFinancialMode $mode,
        array $context,
        User $actor,
    ): void {
        if ($mode !== EpisodeFinancialMode::Mutual) {
            $episode->mutualCoverage?->delete();
        }

        if ($mode !== EpisodeFinancialMode::Staff) {
            $episode->staffCoverage?->delete();
        }

        if ($mode === EpisodeFinancialMode::Mutual) {
            EpisodeMutualCoverage::query()->updateOrCreate(
                ['episode_id' => $episode->getKey()],
                [
                    'mutual_organization_id' => $context['mutual_organization_id'],
                    'employer_name' => $context['employer_name'],
                    'beneficiary_type' => $context['beneficiary_type'],
                    'membership_number' => $context['membership_number'],
                    'organization_uuid_snapshot' => $context['mutual_organization_uuid'],
                    'organization_name_snapshot' => $context['mutual_organization_name'],
                    'coverage_rate_snapshot' => $context['coverage_rate'],
                    'created_by' => $episode->mutualCoverage?->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ],
            );
        }

        if ($mode === EpisodeFinancialMode::Staff) {
            EpisodeStaffCoverage::query()->updateOrCreate(
                ['episode_id' => $episode->getKey()],
                [
                    'employee_id' => $context['employee_id'],
                    'created_by' => $episode->staffCoverage?->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ],
            );
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Episode $episode): array
    {
        $mode = $episode->financial_mode;

        if ($mode === EpisodeFinancialMode::Mutual && $episode->mutualCoverage) {
            return [
                'financial_mode' => $mode->value,
                'mutual_organization_uuid' => $episode->mutualCoverage->organization_uuid_snapshot,
                'mutual_organization_name' => $episode->mutualCoverage->organization_name_snapshot,
                'coverage_rate' => $episode->mutualCoverage->coverage_rate_snapshot,
                'employer_name' => $episode->mutualCoverage->employer_name,
                'beneficiary_type' => $episode->mutualCoverage->beneficiary_type->value,
                'membership_number' => $episode->mutualCoverage->membership_number,
            ];
        }

        if ($mode === EpisodeFinancialMode::Staff && $episode->staffCoverage) {
            return [
                'financial_mode' => $mode->value,
                'employee_uuid' => $episode->staffCoverage->employee?->uuid,
            ];
        }

        return ['financial_mode' => $mode?->value];
    }

    /** @param array<string, mixed> $old @param array<string, mixed> $new */
    private function isSameContext(array $old, array $new): bool
    {
        return collect($old)->map(fn ($value) => $value instanceof \BackedEnum ? $value->value : (string) $value)->all()
            === collect($new)
                ->except(['mutual_organization_id', 'employee_id'])
                ->map(fn ($value) => $value instanceof \BackedEnum ? $value->value : (string) $value)
                ->all();
    }
}
