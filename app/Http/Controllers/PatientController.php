<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only référentiel patients (client CDCF §30.1 Phase 1 item 2) —
 * search and consult. Creating a patient is Réception's job (§5.2.1), see
 * ReceptionController: a patient is never created outside the context of
 * an arrival/passage.
 */
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
