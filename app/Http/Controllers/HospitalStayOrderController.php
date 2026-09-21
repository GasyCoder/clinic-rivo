<?php

namespace App\Http\Controllers;

use App\Actions\Hospitalization\CancelStayReferralAction;
use App\Actions\Hospitalization\DischargeHospitalStayAction;
use App\Actions\Hospitalization\RecordHospitalStayNoteAction;
use App\Actions\Medicine\CancelCareOrderItemAction;
use App\Actions\Medicine\CancelParaclinicalRequestAction;
use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CreateCareOrderAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Actions\Medicine\CreateMedicalReferralAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Enums\PrescriptionStatus;
use App\Http\Requests\Hospitalization\CancelStayPrescriptionRequest;
use App\Http\Requests\Hospitalization\DischargeHospitalStayRequest;
use App\Http\Requests\Hospitalization\StoreHospitalStayNoteRequest;
use App\Http\Requests\Hospitalization\StoreStayCareOrderRequest;
use App\Http\Requests\Hospitalization\StoreStayImagingRequestRequest;
use App\Http\Requests\Hospitalization\StoreStayLabRequestRequest;
use App\Http\Requests\Hospitalization\StoreStayPrescriptionRequest;
use App\Http\Requests\Hospitalization\StoreStayReferralRequest;
use App\Models\CareOrderItem;
use App\Models\HospitalStay;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MedicalReferral;
use App\Models\Prescription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-162 — le séjour, poste de travail du patient hospitalisé.
 *
 * Chaque geste appelle l'action qui porte déjà sa règle (réservation FEFO,
 * facturation, doublons, orientation vers le service) par sa variante « depuis
 * le séjour » : aucun circuit n'est recopié, et aucune consultation n'est
 * ouverte pour un patient déjà au lit.
 */
class HospitalStayOrderController extends Controller
{
    public function storeNote(StoreHospitalStayNoteRequest $request, HospitalStay $hospitalStay, RecordHospitalStayNoteAction $action): RedirectResponse
    {
        $action->execute($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', 'Note du jour enregistrée.');
    }

    public function storePrescription(StoreStayPrescriptionRequest $request, HospitalStay $hospitalStay, CreatePrescriptionAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $request->validated('lines'), $request->user());

        return back()->with('status', 'Ordonnance transmise à la Pharmacie : délivrance au service, sans attendre le règlement.');
    }

    public function cancelPrescription(
        CancelStayPrescriptionRequest $request,
        HospitalStay $hospitalStay,
        Prescription $prescription,
        CancelPrescriptionAction $action,
    ): RedirectResponse {
        $action->execute($prescription, $request->validated('reason'), $request->user());

        return back()
            ->with('status', 'Ordonnance retirée. Le stock réservé a été libéré.')
            ->with('status_type', 'warning');
    }

    public function printPrescription(HospitalStay $hospitalStay, Prescription $prescription): Response
    {
        $this->ensureStayPrescription($hospitalStay, $prescription);
        abort_unless($prescription->status === PrescriptionStatus::Active, 409, 'Seule une ordonnance active peut être imprimée.');

        $hospitalStay->load('episode.patient');
        $prescription->load(['prescribedBy:id,name', 'lines' => fn ($query) => $query->orderBy('id'), 'lines.medicine.catalogItem:id,unit']);
        $episode = $hospitalStay->episode;
        $patient = $episode->patient;

        return Inertia::render('Medicine/PrescriptionPrint', [
            'orientation' => ['uuid' => null],
            'backHref' => "/hospitalisation/{$hospitalStay->uuid}",
            'episode' => [
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
            ],
            'patient' => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex' => $patient->sex->value,
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
            ],
            'prescription' => [
                'uuid' => $prescription->uuid,
                'prescribed_at' => $prescription->prescribed_at ?? $prescription->created_at,
                'prescribed_by' => $prescription->prescribedBy?->name,
                'lines' => $prescription->lines->map(fn ($line) => [
                    'id' => $line->getKey(),
                    'medication_name' => $line->medication_name,
                    'quantity' => $line->quantity,
                    'unit' => $line->medicine?->catalogItem?->unit,
                    'dosage' => $line->dosage,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'instructions' => $line->instructions,
                ])->values(),
            ],
        ]);
    }

    public function storeLabRequest(StoreStayLabRequestRequest $request, HospitalStay $hospitalStay, CreateLabRequestAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $request->validated('items'), $request->validated('notes'), $request->user());

        return back()->with('status', 'Analyses transmises au Laboratoire.');
    }

    public function storeImagingRequest(StoreStayImagingRequestRequest $request, HospitalStay $hospitalStay, CreateImagingRequestAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $request->validated('items'), $request->validated('notes'), $request->user());

        return back()->with('status', 'Examen d’imagerie demandé.');
    }

    /** ADR-163 — retirer des analyses sans résultat, depuis le séjour. */
    public function cancelLabRequest(Request $request, HospitalStay $hospitalStay, LabRequest $labRequest, CancelParaclinicalRequestAction $action): RedirectResponse
    {
        abort_unless($labRequest->hospital_stay_id === $hospitalStay->getKey(), 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->executeForStay($hospitalStay, $labRequest, $validated['reason'] ?? null, $request->user());

        return back()->with('status', 'Analyses retirées. Ce qu’elles avaient porté au compte du patient est annulé.')->with('status_type', 'warning');
    }

    /** ADR-163 — retirer un examen d'imagerie sans compte rendu, depuis le séjour. */
    public function cancelImagingRequest(Request $request, HospitalStay $hospitalStay, ImagingRequest $imagingRequest, CancelParaclinicalRequestAction $action): RedirectResponse
    {
        abort_unless($imagingRequest->hospital_stay_id === $hospitalStay->getKey(), 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->executeForStay($hospitalStay, $imagingRequest, $validated['reason'] ?? null, $request->user());

        return back()->with('status', 'Examen d’imagerie retiré. Ce qu’il avait porté au compte du patient est annulé.')->with('status_type', 'warning');
    }

    public function storeCareOrder(StoreStayCareOrderRequest $request, HospitalStay $hospitalStay, CreateCareOrderAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $request->validated('items'), $request->validated('instructions'), $request->user());

        return back()->with('status', 'Soins demandés à l’équipe infirmière.');
    }

    public function cancelCareOrderItem(Request $request, HospitalStay $hospitalStay, CareOrderItem $careOrderItem, CancelCareOrderItemAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $careOrderItem, $request->user());

        return back()->with('status', 'Acte retiré de la demande de soins.');
    }

    public function storeReferral(StoreStayReferralRequest $request, HospitalStay $hospitalStay, CreateMedicalReferralAction $action): RedirectResponse
    {
        $action->executeForStay($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', 'Transfert demandé. Le patient reste au lit jusqu’à son départ, constaté dans le module Transferts.');
    }

    /** ADR-163 — retirer un transfert tant que le patient n'est pas parti. */
    public function cancelReferral(Request $request, HospitalStay $hospitalStay, MedicalReferral $medicalReferral, CancelStayReferralAction $action): RedirectResponse
    {
        abort_unless($medicalReferral->hospital_stay_id === $hospitalStay->getKey(), 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->execute($hospitalStay, $medicalReferral, $validated['reason'] ?? null, $request->user());

        return back()->with('status', 'Transfert annulé. Le patient reste hospitalisé.')->with('status_type', 'warning');
    }

    /** ADR-162 — la sortie médicale d'un patient hospitalisé, prononcée ici et là seulement. */
    public function discharge(DischargeHospitalStayRequest $request, HospitalStay $hospitalStay, DischargeHospitalStayAction $action): RedirectResponse
    {
        $discharge = $action->execute($hospitalStay, $request->validated(), $request->user());

        // ADR-107 — un décès prononcé conduit au registre, où s'établit l'acte.
        if ($discharge->type->value === 'DECEASED' && $request->user()->can('death_records.view')) {
            return redirect('/deces')->with('status', 'Décès prononcé. Établissez l’acte de constatation.');
        }

        return back()->with('status', 'Sortie médicale prononcée : le séjour est terminé.');
    }

    private function ensureStayPrescription(HospitalStay $stay, Prescription $prescription): void
    {
        abort_unless($prescription->hospital_stay_id === $stay->getKey(), 404);
    }
}
