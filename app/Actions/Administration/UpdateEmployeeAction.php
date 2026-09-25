<?php

namespace App\Actions\Administration;

use App\Actions\Patient\UpdatePatientAction;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\PatientStaffLink;
use App\Models\User;
use App\Services\Administration\EmployeeAddressResolver;
use App\Services\Administration\EmployeeIdentityNormalizer;
use App\Services\Administration\EmployeePatientIdentityMapper;
use App\Services\Administration\EmployeePhotoStore;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateEmployeeAction
{
    public function __construct(
        private readonly EmployeeAddressResolver $addressResolver,
        private readonly EmployeeIdentityNormalizer $identityNormalizer,
        private readonly EmployeePatientIdentityMapper $patientIdentityMapper,
        private readonly UpdatePatientAction $updatePatient,
        private readonly HrReferenceResolver $referenceResolver,
        private readonly EmployeePhotoStore $photos,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Employee $employee, array $data, User $actor): Employee
    {
        // ADR-190 : l'email de la fiche n'est posé que par la création de l'adresse
        // professionnelle ; une fiche enregistrée ne l'écrit ni ne l'efface jamais.
        unset($data['email']);

        Gate::forUser($actor)->authorize('update', $employee);

        // ADR-194 — une nouvelle photo remplace l'ancienne ; « retirer » la
        // supprime. L'ancien fichier n'est effacé qu'une fois le dossier écrit.
        $photo = $data['photo'] ?? null;
        $removePhoto = (bool) ($data['remove_photo'] ?? false);
        unset($data['photo'], $data['remove_photo']);
        $newPhotoPath = $photo instanceof UploadedFile ? $this->photos->store($photo) : null;
        $replacedPhotoPath = null;

        try {
            $updated = DB::transaction(function () use ($employee, $data, $actor, $newPhotoPath, $removePhoto, &$replacedPhotoPath): Employee {
                return $this->write($employee, $data, $actor, $newPhotoPath, $removePhoto, $replacedPhotoPath);
            });
        } catch (Throwable $exception) {
            $this->photos->delete($newPhotoPath);

            throw $exception;
        }

        $this->photos->delete($replacedPhotoPath);

        return $updated;
    }

    /** @param array<string, mixed> $data */
    private function write(Employee $employee, array $data, User $actor, ?string $newPhotoPath, bool $removePhoto, ?string &$replacedPhotoPath): Employee
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

        if ($newPhotoPath || ($removePhoto && $employee->photo_path)) {
            $replacedPhotoPath = $employee->photo_path;
            $data['photo_path'] = $newPhotoPath;
            $data['photo_updated_at'] = $newPhotoPath ? now() : null;
        }

        $data = $this->identityNormalizer->normalize($data);
        // ADR-194 — le couple département / fonction déjà enregistré reste toléré.
        $data = $this->referenceResolver->employeeData($data, $employee);
        $data = $this->addressResolver->resolve($data, $actor, $employee);

        $staffLink = PatientStaffLink::query()
            ->active()
            ->where('employee_id', $employee->getKey())
            ->lockForUpdate()
            ->first();

        $candidateBirthDate = array_key_exists('birth_date', $data)
            ? $data['birth_date']
            : $employee->birth_date;

        if ($staffLink && empty($candidateBirthDate)) {
            throw ValidationException::withMessages([
                'birth_date' => 'La date de naissance reste obligatoire tant que cet employé est relié à un dossier patient.',
            ]);
        }

        $employee->fill($data)->save();
        $employee->load(['addressEntry' => fn ($query) => $query->withTrashed()]);

        if ($staffLink) {
            $patient = Patient::withTrashed()
                ->lockForUpdate()
                ->findOrFail($staffLink->patient_id);

            $this->updatePatient->execute(
                $patient,
                $this->patientIdentityMapper->map($employee),
                $actor,
            );
        }

        return $employee->refresh()->load([
            'addressEntry' => fn ($query) => $query->withTrashed(),
            'department' => fn ($query) => $query->withTrashed(),
            'jobTitle' => fn ($query) => $query->withTrashed(),
        ]);
    }
}
