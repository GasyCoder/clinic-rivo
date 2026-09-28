<?php

namespace App\Support\Paraclinical;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use App\Support\ImagingReportDocument;

/**
 * Une demande d'examen telle que l'écran d'un service demandeur la lit.
 *
 * Écrite une fois pour le séjour (ADR-162) et la Maternité (ADR-204) : la
 * même forme alimente le même composant (`StayExams`). Le résultat reste lu
 * sur la demande — le Laboratoire et le compte rendu d'imagerie sont la seule
 * source ; rien n'est recopié dans le dossier du service.
 */
final class ParaclinicalRequestPresenter
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly ImagingReportTemplateCatalog $templates,
    ) {}

    /** Retirable tant qu'aucun résultat n'est saisi (ADR-079, ADR-163). */
    public static function withdrawable(LabRequest|ImagingRequest $request): bool
    {
        return $request->cancelled_at === null
            && $request->items->every(fn ($item) => ! self::itemStarted($item));
    }

    /**
     * Une ligne a-t-elle déjà produit quelque chose ? Pour une analyse, une
     * saisie commencée à la paillasse compte autant qu'un résultat rendu
     * (ADR-213) : le prélèvement a été analysé, l'acte a eu lieu.
     */
    public static function itemStarted(mixed $item): bool
    {
        return $item instanceof LabRequestItem ? $item->hasStarted() : $item->resulted_at !== null;
    }

    /**
     * D'où vient la demande — dit, jamais déduit d'un libellé.
     */
    public static function origin(LabRequest|ImagingRequest $request): string
    {
        return match (true) {
            $request->maternity_record_id !== null => 'Maternité',
            $request->hospital_stay_id !== null => 'Hospitalisation',
            $request->consultation_id !== null => 'Médecine',
            default => 'Réception',
        };
    }

    /** @return array<string, mixed> */
    public function lab(LabRequest $request, bool $canCancel): array
    {
        return [
            'uuid' => $request->uuid,
            'status' => $request->displayStatus(),
            'requested_at' => $request->requested_at,
            'requested_by' => $request->requestedBy?->name,
            'notes' => $request->notes,
            'cancel_reason' => $request->cancel_reason,
            'origin' => self::origin($request),
            'can_cancel' => $canCancel && self::withdrawable($request),
            'items' => $request->items->map(fn ($item): array => [
                'uuid' => $item->uuid,
                'exam' => $item->catalog_item_name_snapshot,
                'resulted_at' => $item->resulted_at,
                'result' => $item->result_value,
                // ADR-213 — un résultat rendu n'est pas forcément validé par le biologiste.
                'lab_status' => $item->currentStatus()->value,
                'lab_status_label' => $item->currentStatus()->label(),
                'validated_at' => $item->validated_at,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function imaging(ImagingRequest $request, bool $canRecord, bool $canCorrect, bool $canCancel): array
    {
        return [
            'uuid' => $request->uuid,
            'status' => $request->displayStatus(),
            'requested_at' => $request->requested_at,
            'requested_by' => $request->requestedBy?->name,
            'notes' => $request->notes,
            'cancel_reason' => $request->cancel_reason,
            'origin' => self::origin($request),
            'can_cancel' => $canCancel && self::withdrawable($request),
            'items' => $request->items->map(fn ($item): array => [
                'uuid' => $item->uuid,
                'exam' => $item->catalog_item_name_snapshot,
                'resulted_at' => $item->resulted_at,
                'report_raw' => $item->result_value,
                'notes_raw' => $item->result_notes,
                'default_template_key' => $this->templates->defaultKeyFor($item),
                'document' => $item->resulted_at !== null
                    ? ImagingReportDocument::for($item->setRelation('imagingRequest', $request), $this->richText)
                    : null,
                'print_url' => $item->resulted_at !== null ? "/medicine/imaging-requests/{$item->uuid}/compte-rendu" : null,
                'can_record' => $canRecord && $item->resulted_at === null && $request->cancelled_at === null,
                'can_correct' => $canCorrect && $item->resulted_at !== null && $request->cancelled_at === null,
            ])->values()->all(),
        ];
    }

    /** Les relations que `imaging()` lit : chargées une fois, pas une requête par examen. */
    public const IMAGING_RELATIONS = [
        'items.catalogItem:id,imaging_modality',
        'requestedBy:id,name',
        'episode.patient.addressEntry:id,label',
    ];

    public const LAB_RELATIONS = ['items', 'requestedBy:id,name'];

    /**
     * Le catalogue d'un service demandeur : ce qui se demande, jamais un prix.
     *
     * @return list<array{uuid: string, code: string, name: string, modality?: ?string}>
     */
    public static function catalog(CatalogModule $module): array
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', $module->value)
            ->orderBy('name')
            ->get(['uuid', 'code', 'name', 'imaging_modality'])
            ->map(fn (CatalogItem $item) => $module === CatalogModule::Imaging
                ? ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'modality' => $item->imaging_modality?->value]
                : ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])
            ->values()
            ->all();
    }
}
