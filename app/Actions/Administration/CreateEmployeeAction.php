<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\EmployeeAddressResolver;
use App\Services\Administration\EmployeeIdentityNormalizer;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Administration\EmployeePhotoStore;
use App\Services\Administration\HrReferenceResolver;
use App\Support\Hr\EmployeePayroll;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CreateEmployeeAction
{
    public function __construct(
        private readonly EmployeeAddressResolver $addressResolver,
        private readonly EmployeeIdentityNormalizer $identityNormalizer,
        private readonly HrReferenceResolver $referenceResolver,
        private readonly EmployeeNumberAllocator $numbers,
        private readonly EmployeePhotoStore $photos,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): Employee
    {
        // ADR-190 : l'email de la fiche n'est posé que par la création de l'adresse
        // professionnelle ; une fiche enregistrée ne l'écrit ni ne l'efface jamais.
        unset($data['email']);

        Gate::forUser($actor)->authorize('create', Employee::class);
        // ADR-197 — rémunération et compte bancaire : un droit à part, revérifié ici.
        $data = EmployeePayroll::prepare($data, $actor);

        $photo = $data['photo'] ?? null;
        unset($data['photo'], $data['remove_photo']);
        // ADR-194 — la photo est écrite avant la transaction ; si le dossier
        // n'est pas créé, elle est retirée : aucun fichier sans dossier.
        $photoPath = $photo instanceof UploadedFile ? $this->photos->store($photo) : null;

        try {
            return DB::transaction(function () use ($data, $actor, $photoPath): Employee {
                // ADR-191 — un matricule laissé vide reçoit le prochain du modèle du site.
                if (trim((string) ($data['employee_number'] ?? '')) === '') {
                    $data['employee_number'] = $this->numbers->suggest();
                }

                $data = $this->identityNormalizer->normalize($data);
                $data = $this->referenceResolver->employeeData($data);

                if ($photoPath) {
                    $data['photo_path'] = $photoPath;
                    $data['photo_updated_at'] = now();
                }

                $employee = Employee::query()->create($this->addressResolver->resolve($data, $actor));

                return $employee->load(['addressEntry', 'department', 'jobTitle']);
            });
        } catch (Throwable $exception) {
            $this->photos->delete($photoPath);

            throw $exception;
        }
    }
}
