<?php

namespace App\Actions\Reception;

use App\Actions\Administration\CreateAddressEntryAction;
use App\Actions\Administration\LinkPatientToEmployeeAction;
use App\Actions\Administration\ResolveMutualOrganizationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Patient\CreatePatientAction;
use App\Actions\Patient\CreatePatientMutualCoverageAction;
use App\Actions\Patient\StorePatientMutualCoverageAttachmentsAction;
use App\Actions\Patient\UpdatePatientAction;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Exceptions\DuplicatePatientException;
use App\Models\AddressEntry;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Patient;
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
        private readonly CreatePatientMutualCoverageAction $createMutualCoverage,
        private readonly StorePatientMutualCoverageAttachmentsAction $storeMutualAttachments,
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
                &$storedAttachmentPaths,
            ): Episode {
                if ($existingPatientUuid) {
                    $patient = Patient::query()
                        ->with(['activeStaffLink.employee.addressEntry'])
                        ->where('uuid', $existingPatientUuid)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($patient->patient_type === PatientType::Staff) {
                        if ($existingPatientData !== null) {
                            throw ValidationException::withMessages([
                                'patient_uuid' => 'Les informations d’un patient Personnel doivent être corrigées dans son dossier RH.',
                            ]);
                        }

                        $employee = $patient->activeStaffLink?->employee;

                        if (! $employee || ! $employee->isAvailableForPatientLink()) {
                            throw ValidationException::withMessages([
                                'patient_uuid' => 'Ce dossier Personnel n’est plus relié à un employé actif.',
                            ]);
                        }

                        $patient = $this->synchronizeStaffPatient($patient, $employee);
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

                        if ($patient->patient_type !== PatientType::Staff) {
                            throw ValidationException::withMessages([
                                'employee_uuid' => 'Le dossier patient lié doit être de type Personnel.',
                            ]);
                        }

                        // Employee remains the administrative source of truth
                        // for a STAFF patient. Refresh the linked patient at
                        // every arrival instead of allowing both records to
                        // drift independently.
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
                    $patient = $this->createPatient->execute($newPatientData, $confirmDuplicate);

                    if ($patient->patient_type === PatientType::Mutual) {
                        if (! $mutualData) {
                            throw new \LogicException('Les informations de mutuelle sont requises.');
                        }

                        $organization = $this->resolveMutualOrganization->execute(
                            $mutualData['organization_name'],
                            $actor,
                        );
                        $coverage = $this->createMutualCoverage->execute($patient, [
                            'mutual_organization_uuid' => $organization->uuid,
                            'employer_name' => $mutualData['employer_name'],
                            'beneficiary_type' => $mutualData['beneficiary_type'],
                            'membership_number' => $mutualData['membership_number'],
                        ], $actor);

                        if ($mutualAttachments !== []) {
                            $attachments = $this->storeMutualAttachments->execute($coverage, $mutualAttachments, $actor);
                            $storedAttachmentPaths = $attachments
                                ->map(fn ($attachment) => $attachment->getRawOriginal('path'))
                                ->filter()
                                ->all();
                        }
                    }
                }

                return $this->createEpisode->execute($patient, $priority, $actor);
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
            'patient_type' => PatientType::Staff->value,
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
