<?php

namespace App\Actions\Reception;

use App\Actions\Administration\CreateAddressEntryAction;
use App\Actions\Administration\LinkPatientToEmployeeAction;
use App\Actions\Administration\ResolveMutualOrganizationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Actions\Episode\StoreEpisodeMutualCoverageAttachmentsAction;
use App\Actions\Patient\CreatePatientAction;
use App\Actions\Patient\UpdatePatientAction;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Exceptions\DuplicatePatientException;
use App\Models\AddressEntry;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\ReceptionJourneyDraft;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CDC §5.2.1: "rechercher un patient / créer un patient / créer un passage
 * / orienter" are one Réception function, not separate steps a
 * receptionist triggers independently — a patient is never created in the
 * abstract, only because they've just arrived for a visit. This is the
 * single entry point for that: reuse an existing patient (found by the
 * receptionist beforehand) or create a new one, then always open their
 * episode, in one transaction.
 */
class RegisterArrivalAction
{
    public function __construct(
        private readonly CreatePatientAction $createPatient,
        private readonly UpdatePatientAction $updatePatient,
        private readonly CreateEpisodeAction $createEpisode,
        private readonly CreateAddressEntryAction $createAddress,
        private readonly LinkPatientToEmployeeAction $linkEmployee,
        private readonly ResolveMutualOrganizationAction $resolveMutualOrganization,
        private readonly SetEpisodeFinancialContextAction $setFinancialContext,
        private readonly StoreEpisodeMutualCoverageAttachmentsAction $storeMutualAttachments,
    ) {}

    /**
     * Existing-patient corrections are applied before opening the episode.
     *
     * @param  array<string, mixed>|null  $newPatientData
     * @param  array<string, mixed>|null  $existingPatientData
     *
     * @throws DuplicatePatientException for an unconfirmed new-patient match
     */
    public function execute(
        ?string $existingPatientUuid,
        ?array $newPatientData,
        ?array $existingPatientData = null,
        bool $confirmDuplicate = false,
        EpisodePriority $priority = EpisodePriority::Normal,
        ?User $actor = null,
        ?string $employeeUuid = null,
        ?array $mutualData = null,
        array $mutualAttachments = [],
        array $episodeData = [],
        ?EpisodeFinancialMode $financialMode = null,
        ?array $receptionDraft = null,
    ): Episode {
        $storedAttachmentPaths = [];
        $actor ??= Auth::user();

        if (! $actor) {
            throw new \LogicException('Un acteur authentifié est requis pour enregistrer une arrivée.');
        }

        try {
            return DB::transaction(function () use (
                $existingPatientUuid,
                $newPatientData,
                $existingPatientData,
                $confirmDuplicate,
                $priority,
                $actor,
                $employeeUuid,
                $mutualData,
                $mutualAttachments,
                $episodeData,
                $financialMode,
                $receptionDraft,
                &$storedAttachmentPaths,
            ): Episode {
                $mutualOrganizationUuid = null;

                if ($existingPatientUuid) {
                    $patient = Patient::query()
                        ->with(['activeStaffLink.employee.addressEntry'])
                        ->where('uuid', $existingPatientUuid)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $linkedEmployee = $patient->activeStaffLink?->employee;

                    if ($linkedEmployee) {
                        if ($existingPatientData !== null) {
                            throw ValidationException::withMessages([
                                'patient_uuid' => 'Les informations d’un patient relié au Personnel doivent être corrigées dans son dossier RH.',
                            ]);
                        }

                        if (! $linkedEmployee->isAvailableForPatientLink()) {
                            throw ValidationException::withMessages([
                                'patient_uuid' => 'Ce dossier Personnel n’est plus relié à un employé actif.',
                            ]);
                        }

                        $patient = $this->synchronizeStaffPatient($patient, $linkedEmployee);
                    } elseif ($patient->patient_type === PatientType::Staff) {
                        // Compatibility guard for a legacy STAFF Patient whose
                        // identity link is missing. The legacy category is not
                        // used to choose the new Episode financial mode.
                        throw ValidationException::withMessages([
                            'patient_uuid' => 'Ce dossier Personnel legacy n’est plus relié à un employé actif.',
                        ]);
                    } elseif ($existingPatientData !== null) {
                        $patient = $this->updatePatient->execute($patient, $existingPatientData);
                    }
                } elseif ($employeeUuid) {

                    $employee = Employee::query()
                        ->with(['addressEntry', 'activePatientLink.patient'])
                        ->where('uuid', $employeeUuid)
                        ->where('active', true)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->ensureEmployeeCanBeSynchronized($employee, 'employee_uuid');

                    if ($employee->activePatientLink?->patient) {
                        $patient = $employee->activePatientLink->patient;

                        if ($patient->trashed()) {
                            throw ValidationException::withMessages([
                                'employee_uuid' => 'Le dossier patient lié est archivé et doit être restauré avant une nouvelle arrivée.',
                            ]);
                        }

                        // Employee remains the administrative source of truth
                        // for the identity fields sourced from RH. The link
                        // never implies that this Episode uses STAFF coverage.
                        $patient = $this->synchronizeStaffPatient($patient, $employee);
                    } else {
                        $patient = $this->createPatient->execute(
                            $this->patientDataFromEmployee($employee),
                            $confirmDuplicate,
                        );

                        $this->linkEmployee->execute($patient, $employee, $actor);
                    }
                } else {
                    $newPatientData ??= [];

                    if (! empty($newPatientData['address_entry_uuid'])) {
                        $address = AddressEntry::query()
                            ->where('uuid', $newPatientData['address_entry_uuid'])
                            ->where('active', true)
                            ->firstOrFail();
                        $newPatientData['address_entry_id'] = $address->id;
                        $newPatientData['address'] = $address->label;
                    } elseif (! empty($newPatientData['new_address_label'])) {
                        $address = $this->createAddress->execute($newPatientData['new_address_label'], $actor);
                        $newPatientData['address_entry_id'] = $address->id;
                        $newPatientData['address'] = $address->label;
                    }

                    unset($newPatientData['address_entry_uuid'], $newPatientData['new_address_label']);
                    // The current screen still calls the Episode choice
                    // patient_type. Do not persist that financial choice on
                    // the permanent identity; the existing database default
                    // keeps this legacy presentation field at STANDARD.
                    unset($newPatientData['patient_type']);
                    $patient = $this->createPatient->execute($newPatientData, $confirmDuplicate);
                }

                if ($financialMode === EpisodeFinancialMode::Mutual) {
                    if (! $mutualData) {
                        throw new \LogicException('Les informations de mutuelle sont requises.');
                    }

                    $organization = $this->resolveMutualOrganization->execute(
                        $mutualData['organization_name'],
                        $actor,
                    );
                    $mutualOrganizationUuid = $organization->uuid;
                }

                $episode = $this->createEpisode->execute($patient, $priority, $actor, $episodeData);

                if ($priority !== EpisodePriority::Emergency && $receptionDraft !== null) {
                    ReceptionJourneyDraft::query()->create([
                        'episode_id' => $episode->getKey(),
                        'catalog_lines' => $receptionDraft['catalog_lines'],
                        'designation_deferred' => $receptionDraft['designation_deferred'],
                        'created_by' => $actor->getKey(),
                    ]);
                }

                // The current screen still names this choice patient_type.
                // During the UI transition it is translated once into the
                // Episode context; all downstream pricing ignores the legacy
                // Patient column. Emergencies deliberately stay nullable.
                if ($priority !== EpisodePriority::Emergency && $financialMode !== null) {
                    $context = match ($financialMode) {
                        EpisodeFinancialMode::Self => [],
                        EpisodeFinancialMode::Mutual => [
                            'mutual_organization_uuid' => $mutualOrganizationUuid,
                            'employer_name' => $mutualData['employer_name'] ?? null,
                            'beneficiary_type' => $mutualData['beneficiary_type'] ?? null,
                            'membership_number' => $mutualData['membership_number'] ?? null,
                        ],
                        EpisodeFinancialMode::Staff => ['employee_uuid' => $employeeUuid],
                    };

                    $episode = $this->setFinancialContext->execute($episode, $financialMode, $context, $actor);

                    if ($financialMode === EpisodeFinancialMode::Mutual && $mutualAttachments !== []) {
                        $attachments = $this->storeMutualAttachments->execute(
                            $episode->mutualCoverage,
                            $mutualAttachments,
                            $actor,
                        );
                        $storedAttachmentPaths = $attachments
                            ->map(fn ($attachment) => $attachment->getRawOriginal('path'))
                            ->filter()
                            ->all();
                    }
                }

                return $episode;
            });
        } catch (Throwable $exception) {
            // The attachment action cleans up its own failures. This second
            // guard covers a later rollback of the outer patient/episode
            // transaction so no private orphan file remains on disk.
            Storage::disk('local')->delete($storedAttachmentPaths);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function patientDataFromEmployee(Employee $employee): array
    {
        return [
            'civility' => $employee->civility?->value,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'birth_date' => $employee->birth_date?->toDateString(),
            'sex' => $employee->sex->value,
            'identity_document_type' => $employee->identity_document_type?->value,
            'identity_document_number' => $employee->identity_document_number,
            'marital_status' => $employee->marital_status?->value,
            'children_count' => $employee->children_count,
            'profession' => $employee->profession,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->addressEntry?->label ?? $employee->address,
            'address_entry_id' => $employee->address_entry_id,
        ];
    }

    private function synchronizeStaffPatient(Patient $patient, Employee $employee): Patient
    {
        $this->ensureEmployeeCanBeSynchronized($employee, 'patient_uuid');

        return $this->updatePatient->execute(
            $patient,
            $this->patientDataFromEmployee($employee),
        );
    }

    private function ensureEmployeeCanBeSynchronized(Employee $employee, string $errorKey): void
    {
        if (! $employee->isAvailableForPatientLink()) {
            throw ValidationException::withMessages([
                $errorKey => 'Le dossier Personnel doit être relié à un employé actif.',
            ]);
        }

        if (! $employee->birth_date) {
            throw ValidationException::withMessages([
                $errorKey => 'Le dossier RH doit contenir la date de naissance avant de créer ou synchroniser le dossier patient.',
            ]);
        }
    }
}
