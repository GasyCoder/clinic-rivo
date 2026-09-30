<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\User;
use App\Services\Administration\EmployeeIdentityNormalizer;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Administration\JobTitleDepartmentGuard;
use App\Services\Spreadsheet\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportEmployeesAction
{
    public function __construct(
        private readonly ExcelWorkbook $excel,
        private readonly EmployeeIdentityNormalizer $identityNormalizer,
        private readonly EmployeeNumberAllocator $numbers,
        private readonly JobTitleDepartmentGuard $jobTitles,
    ) {}

    /** @return array{created: int, contracts: int} */
    public function execute(UploadedFile $file, User $actor): array
    {
        Gate::forUser($actor)->authorize('employees.import');
        $rows = $this->excel->rows($file);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune ligne de personnel.']);
        }

        if (count($rows) > 1000) {
            throw ValidationException::withMessages(['file' => 'L’import est limité à 1 000 employés par fichier.']);
        }

        $references = HrReferenceValue::query()->where('active', true)
            ->whereIn('type', [
                HrReferenceType::Department->value,
                HrReferenceType::JobTitle->value,
                HrReferenceType::ContractType->value,
            ])->get()->groupBy(fn ($reference) => $reference->type->value)
            ->map(fn ($values) => $values->keyBy(fn ($reference) => $this->normalize($reference->label)));

        $prepared = [];
        $errors = [];
        $seenNumbers = [];

        // ADR-191 — une ligne sans matricule reçoit le prochain du modèle du site, sans
        // jamais reprendre un matricule écrit ailleurs dans le même fichier.
        $reserved = array_values(array_filter(array_map(fn (array $row) => (string) $this->value($row, 'matricule', 'immatricule'), $rows)));

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = $this->prepareRow($row, $references, $line, $errors);

            if (trim((string) ($data['employee_number'] ?? '')) === '') {
                // Matricule de la clinique (H/F + année d'entrée + jour et mois de naissance) quand la fiche le permet.
                $data['employee_number'] = $this->numbers->fromProfile($data['sex'] ?? null, $data['hire_date'] ?? null, $data['birth_date'] ?? null, $reserved)
                    ?? $this->numbers->sequence(1, $reserved)[0];
                $reserved[] = $data['employee_number'];
            }

            $validator = Validator::make($data, [
                'employee_number' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'first_name' => ['nullable', 'string', 'max:255'],
                'sex' => ['required', 'in:M,F'],
                'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
                'hire_date' => ['nullable', 'date'],
                'phone' => ['nullable', 'string', 'max:50'],
                'children_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'active' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Ligne {$line} : {$message}";
                }
            }

            $number = (string) ($data['employee_number'] ?? '');
            if ($number !== '' && isset($seenNumbers[$number])) {
                $errors[] = "Ligne {$line} : le matricule {$number} est dupliqué dans le fichier.";
            }
            $seenNumbers[$number] = true;
            $prepared[] = $data;
        }

        $existing = Employee::withTrashed()->whereIn('employee_number', array_keys($seenNumbers))
            ->pluck('employee_number')->all();
        foreach ($existing as $number) {
            $errors[] = "Le matricule {$number} existe déjà, y compris dans les archives.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => collect($errors)->unique()->take(20)->join(' '),
            ]);
        }

        return DB::transaction(function () use ($prepared): array {
            $contractCount = 0;

            foreach ($prepared as $data) {
                $contractTypeId = $data['contract_type_id'];
                unset($data['contract_type_id']);
                $data = $this->identityNormalizer->normalize($data);
                $employee = Employee::query()->create($data);

                if ($contractTypeId && $employee->hire_date) {
                    EmploymentContract::query()->create([
                        'employee_id' => $employee->getKey(),
                        'contract_type_id' => $contractTypeId,
                        'starts_on' => $employee->hire_date,
                    ]);
                    $contractCount++;
                }
            }

            return ['created' => count($prepared), 'contracts' => $contractCount];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  mixed  $references
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function prepareRow(array $row, $references, int $line, array &$errors): array
    {
        $jobLabel = $this->value($row, 'fonction', 'fonctions');
        $departmentLabel = $this->value($row, 'departement');
        $contractLabel = $this->value($row, 'type_contrat', 'contrat');
        $job = $jobLabel ? $references->get(HrReferenceType::JobTitle->value)?->get($this->normalize($jobLabel)) : null;
        $department = $departmentLabel ? $references->get(HrReferenceType::Department->value)?->get($this->normalize($departmentLabel)) : null;
        $contract = $contractLabel ? $references->get(HrReferenceType::ContractType->value)?->get($this->normalize($contractLabel)) : null;

        if ($jobLabel && ! $job) {
            $errors[] = "Ligne {$line} : la fonction « {$jobLabel} » n’existe pas dans les paramètres RH.";
        }
        if ($departmentLabel && ! $department) {
            $errors[] = "Ligne {$line} : le département « {$departmentLabel} » n’existe pas dans les paramètres RH.";
        }
        // ADR-194 — la même règle qu'au formulaire : une ligne ne crée pas un
        // « Gardien » au Laboratoire parce qu'elle arrive par un fichier.
        if ($job && $department && ! $this->jobTitles->allows($department, $job)) {
            $errors[] = "Ligne {$line} : la fonction « {$job->label} » n’existe pas dans le département « {$department->label} » (voir le module Fonctions).";
        }
        if ($contractLabel && ! $contract) {
            $errors[] = "Ligne {$line} : le type de contrat « {$contractLabel} » n’existe pas dans les paramètres RH.";
        }

        $lastName = $this->value($row, 'nom');
        $firstName = $this->value($row, 'prenoms', 'prenom');
        if (! $lastName) {
            $lastName = $this->value($row, 'nome_et_prenoms', 'nom_et_prenoms');
        }

        // ADR-190 : aucun email n'est importé — celui d'un employé est son adresse
        // professionnelle, créée après son enregistrement. Une colonne « Email » est ignorée.
        $phone = $this->phoneText($this->value($row, 'telephone', 'tel', 'contact'));
        // La feuille du personnel peut porter deux numéros dans la même cellule.
        $secondPhone = null;
        if (is_string($phone) && preg_match('/^(\+?[\d .-]{6,}?)\s*(?:[\/;,]|\s{2,})\s*(\+?[\d .-]{6,})$/', $phone, $m)) {
            $phone = Str::squish($m[1]);
            $secondPhone = Str::squish($m[2]);
        }
        $combinedContact = $this->value($row, 'email_tel');
        if ($combinedContact && ! $phone && ! str_contains($combinedContact, '@')) {
            $phone = $combinedContact;
        }

        $hireDate = $this->date($this->value($row, 'date_entree'));
        if ($contract && ! $hireDate) {
            $errors[] = "Ligne {$line} : une date d’entrée est requise pour créer le contrat {$contract->label}.";
        }

        return [
            'employee_number' => $this->value($row, 'matricule', 'immatricule'),
            'department_id' => $department?->getKey(),
            'job_title_id' => $job?->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => $this->sex($this->value($row, 'genre', 'sexe')) ?? $this->sexFromNumber($this->value($row, 'matricule', 'immatricule')),
            'birth_date' => $this->date($this->value($row, 'date_naissance')),
            'hire_date' => $hireDate,
            'birth_place' => $this->value($row, 'lieu_naissance'),
            'identity_document_type' => $this->value($row, 'numero_cin') ? 'CIN' : null,
            'identity_document_number' => $this->value($row, 'numero_cin'),
            'identity_document_issued_on' => $this->date($this->value($row, 'date_cin')),
            'identity_document_issued_at' => $this->value($row, 'lieu_cin'),
            'children_count' => $this->value($row, 'nombre_enfants', 'nbre_enfants'),
            'diploma' => $this->value($row, 'diplome'),
            'education_level' => $this->value($row, 'niveau'),
            ...$this->children($row),
            'badge' => $this->value($row, 'badge'),
            'blouse' => $this->value($row, 'blouse'),
            'profession' => $job?->label,
            'phone' => $phone,
            'phone_secondary' => $secondPhone,
            'tshirt_size' => $this->value($row, 'taille_tshirt', 'taille_t_shirt'),
            'blouse_size' => $this->value($row, 'taille_blouse'),
            'bloc_outfit' => $this->value($row, 'tenue_bloc'),
            'shoe_size' => $this->value($row, 'pointure'),
            'scrub_cap' => $this->value($row, 'callot'),
            'clog' => $this->value($row, 'sabot'),
            'address' => $this->value($row, 'adresse'),
            'observation' => $this->value($row, 'observation'),
            'active' => $this->active($this->value($row, 'statut', 'status')),
            // Un « Bénévole » est en poste mais non rémunéré (ADR-206).
            ...($this->isVolunteer($this->value($row, 'statut', 'status')) ? ['remuneration_type' => 'UNPAID'] : []),
            'contract_type_id' => $contract?->getKey(),
        ];
    }

    /**
     * Les enfants : la note de la feuille est relue en liste quand elle se lit
     * entièrement, et le nombre suit alors la liste ; sinon elle reste une note.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function children(array $row): array
    {
        $details = $this->value($row, 'details_enfants', 'prenom_enfants_naissance_sex');
        $list = \App\Support\Hr\EmployeeChildren::parse(is_string($details) ? $details : null);

        if ($list !== null) {
            return ['children' => $list, 'children_details' => null, 'children_count' => count($list)];
        }

        return ['children_details' => $details];
    }

    /** @param array<string, mixed> $row */
    private function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return is_string($row[$key]) ? Str::squish($row[$key]) : $row[$key];
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(Str::squish($value)));
    }

    /** Une cellule numérique perd son 0 initial : 324387865 se relit 0324387865. */
    private function phoneText(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $digits = (string) (int) $value;

            return strlen($digits) === 9 && $digits[0] === '3' ? '0'.$digits : $digits;
        }

        return $value;
    }

    /** La feuille du personnel écrit le sexe en tête du matricule : H (homme) ou F (femme). */
    private function sexFromNumber(mixed $number): ?string
    {
        return match (Str::upper(substr(trim((string) $number), 0, 1))) {
            'H' => 'M',
            'F' => 'F',
            default => null,
        };
    }

    private function isVolunteer(mixed $status): bool
    {
        return in_array(Str::upper(Str::ascii(trim((string) $status))), ['BENEVOLE', 'BENEVOLAT'], true);
    }

    private function sex(mixed $value): ?string
    {
        return match (Str::upper(Str::ascii(trim((string) $value)))) {
            'M', 'H', 'HOMME', 'MASCULIN' => 'M',
            'F', 'FEMME', 'FEMININ' => 'F',
            default => null,
        };
    }

    private function active(mixed $value): ?bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        return match (Str::upper(Str::ascii(trim((string) $value)))) {
            '1', 'OUI', 'YES', 'ACTIF', 'ACTIVE', 'BENEVOLE', 'BENEVOLAT' => true,
            '0', 'NON', 'NO', 'INACTIF', 'INACTIVE' => false,
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, trim((string) $value))->toDateString();
            } catch (\Throwable) {
                // Try the next explicitly supported format.
            }
        }

        return (string) $value;
    }
}
