<?php

namespace App\Support\Hospitalization;

use App\Enums\AdministrationRoute;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicalDischargeType;
use App\Enums\MedicalRequestStatus;
use App\Models\CareOrder;
use App\Models\CatalogItem;
use App\Models\Diagnosis;
use App\Models\HospitalStay;
use App\Models\HospitalStayNote;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MedicalReferral;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Care\CareRecordReadModel;
use App\Services\Medicine\ClinicalProtocolMatcher;
use App\Services\Medicine\ClinicPracticeAdvisor;
use App\Services\Medicine\ClinicPracticeIndex;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use App\Services\Pharmacy\MedicineStockService;
use App\Support\CareRequestSummary;
use App\Support\ClinicSites;
use App\Support\Medicine\PrescriptionDocument;
use App\Support\Medicine\PrescriptionSuggestions;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;

/**
 * ADR-162 — ce que la page du séjour sert au poste de travail du patient
 * hospitalisé : les demandes du séjour, leurs catalogues et ce que le dossier
 * a déjà relevé.
 *
 * Chaque section garde la permission qui possède sa donnée (ADR-054) : sans le
 * droit, elle n'est pas servie — jamais servie vide, qui se lirait « rien n'a
 * été demandé ».
 */
final class HospitalStayWorkstation
{
    public function __construct(
        private readonly MedicineStockService $medicineStock,
        private readonly CareRecordReadModel $careRecordReadModel,
        private readonly ImagingReportTemplateCatalog $templates,
        private readonly ClinicalProtocolMatcher $protocols,
        private readonly ClinicPracticeAdvisor $practice,
        private readonly PrescriptionSuggestions $prescriptionSuggestions,
        private readonly ParaclinicalRequestPresenter $paraclinical,
    ) {}

    /** @return array<string, mixed> */
    public function present(HospitalStay $stay, User $user): array
    {
        $active = $stay->isActive();
        $canPrescribe = $active && $user->can('prescriptions.create')
            && $user->can('medicines.view') && $user->can('stock.availability.view');

        return [
            // Les constantes du triage (fiche Soins) : reprises, jamais ressaisies.
            'careRecord' => $this->careRecordReadModel->present(
                $stay->episode->careRecord()->first(),
                $user,
            ),
            'notes' => $user->can('hospital_notes.view') ? $this->notes($stay) : null,
            'prescriptions' => $user->can('prescriptions.view') ? $this->prescriptions($stay, $user) : null,
            // ADR-163 — l'ordonnance proposée pour les diagnostics du passage,
            // comme en consultation (ADR-111). Servie seulement à qui prescrit.
            'prescriptionSuggestions' => $canPrescribe ? $this->suggestions($stay, $user) : null,
            'labRequests' => $user->can('laboratory_orders.view') ? $this->labRequests($stay, $user) : null,
            'imagingRequests' => $user->can('imaging_orders.view') ? $this->imagingRequests($stay, $user) : null,
            'careOrders' => $user->can('care_orders.view') ? $this->careOrders($stay, $user) : null,
            'referral' => $this->referral($stay, $user),
            'orderOptions' => [
                'medicines' => $canPrescribe ? $this->medicineStock->availableCatalog() : [],
                'lab_catalog' => $active && $user->can('laboratory_orders.create') ? $this->catalog(CatalogModule::Laboratory) : [],
                'imaging_catalog' => $active && $user->can('imaging_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Imaging->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name', 'imaging_modality'])
                        ->map(fn (CatalogItem $item) => [
                            'uuid' => $item->uuid,
                            'code' => $item->code,
                            'name' => $item->name,
                            'modality' => $item->imaging_modality?->value,
                        ])->all()
                    : [],
                'care_order_catalog' => $active && $user->can('care_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Care->value)
                        ->where('clinician_orderable', true)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                        ->all()
                    : [],
                'administration_routes' => collect(AdministrationRoute::cases())->map(fn (AdministrationRoute $route) => [
                    'value' => $route->value, 'label' => $route->label(), 'short_label' => $route->shortLabel(),
                ])->values()->all(),
                // ADR-161 — un patient au lit est transféré au départ de
                // l'ambulance : « Transfert » ne se prononce pas comme sortie.
                'discharge_types' => collect(MedicalDischargeType::cases())
                    ->reject(fn (MedicalDischargeType $type): bool => $type === MedicalDischargeType::Transfer)
                    ->map(fn (MedicalDischargeType $type) => ['value' => $type->value, 'label' => $type->label()])
                    ->values()->all(),
                'imaging_report_templates' => $user->can('imaging_results.create') ? $this->templates->all() : [],
                'imaging_report_template_rights' => $this->templates->rightsFor($user),
                // La fenêtre de compte rendu s'adresse à l'orientation qui a
                // émis la demande : celle du séjour (ADR-162).
                'stay_orientation_uuid' => $stay->episodeOrientation()->value('uuid'),
                // Les autres sites de la clinique, proposés au transfert ; un
                // établissement extérieur reste une saisie libre.
                'transfer_destinations' => $active && $user->can('transfer.request') ? ClinicSites::others() : [],
            ],
            'orderCapabilities' => [
                'can_write_note' => $active && $user->can('hospital_notes.create'),
                'can_prescribe' => $canPrescribe,
                'can_request_lab' => $active && $user->can('laboratory_orders.create'),
                'can_request_imaging' => $active && $user->can('imaging_orders.create'),
                'can_request_care' => $active && $user->can('care_orders.create'),
                'can_request_transfer' => $active && $user->can('transfer.request'),
                'can_record_imaging' => $user->can('imaging_results.create'),
                'can_correct_imaging' => $user->can('imaging_results.update'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notes(HospitalStay $stay): array
    {
        return $stay->notes()
            ->with('writtenBy:id,name')
            ->orderByDesc('written_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (HospitalStayNote $note): array => [
                'uuid' => $note->uuid,
                'subjective' => $note->subjective,
                'objective' => $note->objective,
                'assessment' => $note->assessment,
                'plan' => $note->plan,
                'written_at' => $note->written_at,
                'written_by' => $note->writtenBy?->name,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function prescriptions(HospitalStay $stay, User $user): array
    {
        $canCancel = $stay->isActive() && $user->can('prescriptions.cancel');

        return Prescription::query()
            ->where('hospital_stay_id', $stay->getKey())
            ->with([...PrescriptionDocument::RELATIONS, 'lines'])
            ->orderByDesc('prescribed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Prescription $prescription): array => PrescriptionDocument::listItem(
                $prescription,
                $canCancel,
                "/hospitalisation/{$stay->uuid}/ordonnances/{$prescription->uuid}/impression",
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function labRequests(HospitalStay $stay, User $user): array
    {
        // ADR-163 — retirable depuis le séjour tant qu'aucun résultat n'est
        // saisi (même règle qu'en consultation, ADR-079).
        $canCancel = $stay->isActive() && $user->can('laboratory_orders.create');

        return LabRequest::query()
            ->where('hospital_stay_id', $stay->getKey())
            ->with(ParaclinicalRequestPresenter::LAB_RELATIONS)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (LabRequest $request): array => $this->paraclinical->lab($request, $canCancel))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function imagingRequests(HospitalStay $stay, User $user): array
    {
        $canCancel = $stay->isActive() && $user->can('imaging_orders.create');

        return ImagingRequest::query()
            ->where('hospital_stay_id', $stay->getKey())
            ->with(ParaclinicalRequestPresenter::IMAGING_RELATIONS)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (ImagingRequest $request): array => $this->paraclinical->imaging(
                $request,
                $user->can('imaging_results.create'),
                $user->can('imaging_results.update'),
                $canCancel,
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function careOrders(HospitalStay $stay, User $user): array
    {
        $canCancel = $stay->isActive() && $user->can('care_orders.create');

        return CareOrder::query()
            ->where('hospital_stay_id', $stay->getKey())
            ->with(['requestedBy:id,name', 'items.careRecordProcedures'])
            ->orderByDesc('ordered_at')
            ->get()
            ->map(fn (CareOrder $order): array => [
                'uuid' => $order->uuid,
                'status' => $order->status->value,
                'ordered_at' => $order->ordered_at,
                'requested_by' => $order->requestedBy?->name,
                'instructions' => $order->instructions,
                'items' => collect(CareRequestSummary::items(collect([$order])))
                    ->values()
                    ->map(fn (array $item, int $index): array => [
                        ...$item,
                        'uuid' => $order->items->values()[$index]->uuid,
                        'can_cancel' => $canCancel && $item['state'] === 'PENDING',
                    ])->all(),
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function referral(HospitalStay $stay, User $user): ?array
    {
        $referral = MedicalReferral::query()
            ->where('hospital_stay_id', $stay->getKey())
            ->where('status', '!=', MedicalRequestStatus::Cancelled->value)
            ->with('referredBy:id,name')
            ->latest('id')
            ->first();

        return $referral ? [
            'uuid' => $referral->uuid,
            'facility' => $referral->facility,
            'priority_label' => $referral->priority?->label(),
            'referred_at' => $referral->referred_at,
            'referred_by' => $referral->referredBy?->name,
            'departed_at' => $referral->departed_at,
            'url' => $user->can('transfers.view') ? "/transferts/{$referral->uuid}" : null,
            // ADR-163 — tant que le patient n'est pas parti, la demande se retire.
            'can_cancel' => $stay->isActive() && $referral->departed_at === null && $user->can('transfer.request'),
        ] : null;
    }

    /** Une demande encore en cours, sans aucun résultat : le médecin peut y renoncer. */
    /**
     * ADR-163 — les propositions d'ordonnance pour les diagnostics du passage.
     *
     * @return array<string, mixed>
     */
    private function suggestions(HospitalStay $stay, User $user): array
    {
        $context = $this->protocols->stayContext($stay);

        return [
            'protocol_count' => $this->protocols->activeProtocolCount(),
            'practice_cases' => $this->practice->caseCount(),
            'practice_min_cases' => ClinicPracticeIndex::MIN_CASES,
            'can_manage_protocols' => $user->can('clinical_protocols.manage'),
            // Un diagnostic saisi à la main compte : « posez un diagnostic »
            // serait faux. Seuls ceux du référentiel appellent une ordonnance type.
            'has_diagnosis' => $context['diagnosis_catalog_ids'] !== []
                || $stay->diagnoses()->exists()
                || Diagnosis::query()
                    ->whereHas('consultation', fn ($query) => $query->where('episode_id', $stay->episode_id))
                    ->whereDoesntHave('cancellation')
                    ->exists(),
            'prescription' => $this->prescriptionSuggestions->for($context),
        ];
    }

    /** @return list<array{uuid: string, code: string, name: string}> */
    private function catalog(CatalogModule $module): array
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', $module->value)
            ->orderBy('name')
            ->get(['uuid', 'code', 'name'])
            ->map(fn (CatalogItem $item) => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])
            ->all();
    }
}
