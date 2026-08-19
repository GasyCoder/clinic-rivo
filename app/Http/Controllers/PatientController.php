<?php

namespace App\Http\Controllers;

use App\Actions\Patient\CreatePatientAction;
use App\Exceptions\DuplicatePatientException;
use App\Http\Requests\StorePatientRequest;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $patients = Patient::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Patients/Index', [
            'patients' => $patients,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Patients/Create');
    }

    public function store(StorePatientRequest $request, CreatePatientAction $action): RedirectResponse
    {
        try {
            $patient = $action->execute($request->validated(), confirmDuplicate: $request->boolean('confirm_duplicate'));
        } catch (DuplicatePatientException $e) {
            return back()->withInput()->with('duplicates', $e->matches->map(fn (Patient $p) => [
                'id' => $p->id,
                'patient_number' => $p->patient_number,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'birth_date' => $p->birth_date->toDateString(),
            ])->all());
        }

        return redirect()->route('patients.show', $patient)->with('status', 'Patient créé.');
    }

    public function show(Patient $patient): Response
    {
        $patient->load([
            'antecedents',
            'allergies',
            'episodes' => fn ($query) => $query->orderByDesc('started_at'),
        ]);

        return Inertia::render('Patients/Show', [
            'patient' => $patient,
        ]);
    }
}
