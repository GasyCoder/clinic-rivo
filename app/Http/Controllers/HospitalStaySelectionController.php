<?php

namespace App\Http\Controllers;

use App\Enums\HospitalStayStatus;
use App\Models\HospitalStay;
use App\Services\Audit\Auditor;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Support\Documents\MedicalRecordSheet;
use App\Support\Hospitalization\DietSheet;
use App\Support\HospitalStaySurveillance;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-165 — ce que l'on fait d'un coup sur plusieurs patients hospitalisés.
 *
 * Quatre gestes, tous en lecture : imprimer les fiches de régime, les dossiers
 * médicaux, une feuille de tour de salle, et exporter la liste en Excel. Aucune
 * décision médicale ne se prend en lot — sortie, transfert, bloc et changement
 * de lit restent patient par patient, là où le médecin les signe.
 *
 * Ce que l'écran coche n'est qu'un ensemble d'UUID : borné, sans doublon, relu
 * depuis la base, et jamais une source de vérité. Chaque feuille garde les
 * droits de la feuille seule — une impression groupée ne contourne rien.
 */
class HospitalStaySelectionController extends Controller
{
    public const BULK_LIMIT = 50;

    private const BACK = ['href' => '/hospitalisation', 'label' => 'Retour à l’hospitalisation'];

    /** Les fiches de régime des séjours cochés, une par page. */
    public function dietSheets(Request $request, DietSheet $sheet): Response
    {
        $stays = $this->selection($request, DietSheet::relations());

        return Inertia::render('Hospitalization/DietSheetsPrint', [
            'sheets' => $stays->map(fn (HospitalStay $stay) => $sheet->present($stay))->values()->all(),
        ]);
    }

    /**
     * Les dossiers médicaux des patients cochés, chacun sur sa page.
     *
     * Chaque dossier est composé comme la feuille seule (ADR-116) : ses sections
     * sensibles restent gardées par leur propre droit. Les onglets mère / bébés
     * ne s'impriment jamais ; ils ne sont donc pas servis ici.
     */
    public function medicalRecords(Request $request, MedicalRecordSheet $sheet): Response
    {
        $stays = $this->selection($request, ['episode.patient']);

        return Inertia::render('Hospitalization/MedicalRecordsPrint', [
            'records' => $stays->map(fn (HospitalStay $stay): array => [
                ...$sheet->present($stay->episode, $request->user()),
                'back' => self::BACK,
                'dossiers' => null,
            ])->values()->all(),
            'back' => self::BACK,
        ]);
    }

    /**
     * Une feuille récapitulative pour la visite : lit, patient, motif, jour de
     * séjour, allergies et dernier relevé.
     *
     * Le dernier relevé n'est servi qu'avec `vitals.view` ; sans ce droit la
     * colonne est absente, jamais vide — une case vide se lirait « pas de
     * relevé ».
     */
    public function wardRound(Request $request, HospitalStaySurveillance $surveillance): Response
    {
        $canVitals = $request->user()->can('vitals.view');
        $stays = $this->selection($request, [
            'episode:id,uuid,episode_number,patient_id,started_at',
            'episode.patient:id,uuid,patient_number,first_name,last_name,sex,birth_date,declared_age',
            'episode.patient.allergies' => fn ($query) => $query->orderBy('substance'),
            'hospitalizationRequest:id,reason,priority',
            'currentMovement',
        ]);

        return Inertia::render('Hospitalization/WardRoundPrint', [
            'vitals_visible' => $canVitals,
            'generated_at' => now(),
            'stays' => $stays->map(fn (HospitalStay $stay): array => [
                'uuid' => $stay->uuid,
                'episode_number' => $stay->episode->episode_number,
                'patient' => $this->patient($stay),
                'allergies' => $stay->episode->patient?->allergies->map(fn ($allergy) => $allergy->substance)->filter()->values()->all() ?? [],
                'reason' => $stay->hospitalizationRequest?->reason,
                'priority' => $stay->hospitalizationRequest?->priority?->value,
                'service' => $stay->service,
                'room_bed' => $stay->room_bed,
                'care_level' => $stay->currentMovement?->care_level?->value,
                'care_level_label' => $stay->currentMovement?->care_level?->label(),
                'admitted_at' => $stay->admitted_at,
                'status' => $stay->status->value,
                'latest_reading' => $canVitals ? $surveillance->latestReading($stay) : null,
            ])->values()->all(),
        ]);
    }

    /**
     * La liste cochée en Excel — lecture seule, auditée. Elle porte des données
     * personnelles : `hospitalization.export` est exigé en plus de la vue.
     */
    public function export(Request $request, ExcelWorkbook $workbook, Auditor $auditor): StreamedResponse
    {
        $stays = $this->selection($request, [
            'episode:id,uuid,episode_number,patient_id',
            'episode.patient:id,uuid,patient_number,first_name,last_name,sex,birth_date,declared_age',
            'hospitalizationRequest:id,reason,priority,requested_by',
            'hospitalizationRequest.requestedBy:id,name',
            'currentMovement',
        ]);

        $auditor->record(
            'hospitalization.export',
            newValues: ['rows' => $stays->count(), 'episodes' => $stays->map(fn (HospitalStay $stay) => $stay->episode->episode_number)->values()->all()],
            module: 'hospitalization',
        );

        return $workbook->download(
            'hospitalisation-'.now()->format('Y-m-d'),
            'Patients hospitalisés',
            ['N° patient', 'Patient', 'Sexe', 'Âge', 'N° passage', 'Service', 'Chambre / lit', 'Niveau de soins', 'Entrée', 'Jours de séjour', 'Motif', 'Demandé par', 'Situation', 'Fin du séjour'],
            $stays->map(function (HospitalStay $stay): array {
                $patient = $this->patient($stay);

                return [
                    $patient['patient_number'],
                    $patient['name'],
                    $patient['sex'],
                    $patient['age'],
                    $stay->episode->episode_number,
                    $stay->service,
                    $stay->room_bed,
                    $stay->currentMovement?->care_level?->label(),
                    $stay->admitted_at?->format('d/m/Y H:i'),
                    $stay->admitted_at ? (int) $stay->admitted_at->diffInDays($stay->discharged_at ?? now()) : null,
                    $stay->hospitalizationRequest?->reason,
                    $stay->hospitalizationRequest?->requestedBy?->name,
                    $stay->status->label(),
                    $stay->discharged_at?->format('d/m/Y H:i'),
                ];
            })->all(),
        );
    }

    /**
     * Les séjours cochés, relus depuis la base et classés comme on fait le tour
     * des lits : par service, puis par chambre, puis par date d'entrée. Un
     * séjour annulé n'a jamais eu lieu (ADR-163) : il est écarté.
     *
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, HospitalStay>
     */
    private function selection(Request $request, array $relations): Collection
    {
        $uuids = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ], [
            'uuids.required' => 'Cochez au moins un patient.',
            'uuids.max' => 'Au plus '.self::BULK_LIMIT.' patients à la fois.',
        ])['uuids'];

        return HospitalStay::query()
            ->whereIn('uuid', $uuids)
            ->where('status', '!=', HospitalStayStatus::Cancelled->value)
            ->with($relations)
            ->orderByRaw('COALESCE(service, \'\') ASC')
            ->orderByRaw('COALESCE(room_bed, \'\') ASC')
            ->orderBy('admitted_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function patient(HospitalStay $stay): array
    {
        $patient = $stay->episode->patient;

        return [
            'patient_number' => $patient?->patient_number,
            'name' => trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
            'sex' => $patient?->sex?->value ?? $patient?->sex,
            'age' => $patient?->birth_date?->age ?? $patient?->declared_age,
        ];
    }
}
