<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Administration\InternshipDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purpose-specific employee directory for Reception. It never exposes user
 * permissions, HR contracts or other future confidential personnel fields.
 * The route is intentionally wired by the Reception workflow separately.
 *
 * ADR-211 — la même recherche sert deux moments du parcours : l'identité (qui
 * est la personne ? son dossier patient se crée depuis la fiche RH, sans
 * ressaisie) et la prise en charge (Personnel). Elle dit donc aussi si la
 * personne est stagiaire — retrouvée sans ressaisie, mais au tarif Standard
 * (ADR-194) — et si sa fiche RH porte la date de naissance qu'un dossier
 * patient exige.
 */
class EmployeePatientLookupController extends Controller
{
    public function __invoke(Request $request, InternshipDirectory $internships): JsonResponse
    {
        abort_unless($request->user()?->can('employees.patient_lookup'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $search = trim($validated['q']);

        $employees = Employee::query()
            ->with([
                'activePatientLink.patient:id,uuid,patient_number,first_name,last_name',
                'department:id,label',
                'jobTitle:id,label',
            ])
            ->where('active', true)
            ->where(function ($query) use ($search) {
                $query->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('identity_document_number', 'like', "%{$search}%");
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        $internIds = $internships->internIdsAmong($employees->modelKeys());

        return response()->json(['data' => $employees->map(function (Employee $employee) use ($internIds) {
            $isIntern = $internIds->contains($employee->getKey());
            $eligible = $employee->isAvailableForPatientLink();

            return [
                'uuid' => $employee->uuid,
                'employee_number' => $employee->employee_number,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'profession' => $employee->profession,
                'job_title' => $employee->jobTitle?->label,
                'department' => $employee->department?->label,
                'sex' => $employee->sex?->value,
                'birth_date' => $employee->birth_date?->toDateString(),
                'phone' => $employee->phone,
                'active' => $employee->active,
                'eligible' => $eligible,
                'is_intern' => $isIntern,
                // Personnel : un employé en poste, jamais un stagiaire (ADR-194).
                'staff_coverage_eligible' => $eligible && ! $isIntern,
                // Un dossier patient se crée depuis la fiche RH seulement si elle
                // porte la date de naissance.
                'can_open_patient_record' => $eligible && $employee->birth_date !== null,
                'linked_patient' => $employee->activePatientLink?->patient ? [
                    'uuid' => $employee->activePatientLink->patient->uuid,
                    'patient_number' => $employee->activePatientLink->patient->patient_number,
                    'first_name' => $employee->activePatientLink->patient->first_name,
                    'last_name' => $employee->activePatientLink->patient->last_name,
                ] : null,
            ];
        })->values()]);
    }
}
