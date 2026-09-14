<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CareOrderStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The DEMANDE a doctor makes for one or more Soins acts, from an active
 * Consultation — kept apart from the ORIENTATION that routes the patient
 * (EpisodeOrientation), the ACTE RÉALISÉ Soins records (CareRecordProcedure)
 * and any FACTURATION that may follow (BillableItem). None of those four
 * are ever fused here.
 */
class CreateCareOrderAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
    ) {}

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string, instructions?: ?string}>  $items
     */
    public function execute(
        Consultation $consultation,
        array $items,
        bool $requiresReturnToMedicine,
        ?string $instructions,
        User $actor,
    ): CareOrder {
        return DB::transaction(function () use ($consultation, $items, $requiresReturnToMedicine, $instructions, $actor): CareOrder {
            $lockedConsultation = Consultation::query()
                ->lockForUpdate()
                ->findOrFail($consultation->getKey());

            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'care_order' => 'Cette consultation n’est plus active.',
                ]);
            }

            $episode = $medicineOrientation->episode;

            $catalogUuids = collect($items)->pluck('catalog_item_uuid')->unique();
            $catalogItems = CatalogItem::query()
                ->whereIn('uuid', $catalogUuids)
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Care->value)
                ->where('clinician_orderable', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($catalogItems->count() !== $catalogUuids->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Un acte sélectionné n’est plus disponible ou ne peut pas être demandé par un médecin.',
                ]);
            }

            // One pending request per act: asking Soins again for an act
            // that is still waiting would put the same act twice in their
            // queue. Resolved acts (done, or marked not performed) can be
            // asked for again; a different act is a new, legitimate request.
            $stillPending = CareOrderItem::query()
                ->whereIn('catalog_item_id', $catalogItems->pluck('id'))
                ->whereHas('careOrder', fn ($query) => $query
                    ->where('consultation_id', $lockedConsultation->getKey())
                    ->where('status', CareOrderStatus::Pending->value))
                ->with(['careOrder', 'careRecordProcedures'])
                ->lockForUpdate()
                ->get()
                ->reject(fn (CareOrderItem $item) => $item->isResolved())
                ->first();

            if ($stillPending) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        '« %s » est déjà demandé aux Soins (%s) et n’a pas encore été réalisé.',
                        $stillPending->catalog_item_name_snapshot,
                        $stillPending->careOrder->ordered_at?->format('d/m/Y à H:i'),
                    ),
                ]);
            }

            // The consultation stays open while the patient goes to Soins
            // (ADR-088, amends ADR-055). Only closing the consultation ends
            // Médecine's orientation (ADR-084): completing it here left the
            // encounter "in progress" on a finished orientation — read-only,
            // with no way to record a diagnosis, choose what comes next or
            // close it.

            $careOrientation = $this->createOrientation->execute(
                $episode,
                CatalogModule::Medicine,
                CatalogModule::Care,
                $actor,
                'Ordre de soins demandé en consultation.',
            );

            $careOrder = CareOrder::query()->create([
                'episode_id' => $episode->getKey(),
                'consultation_id' => $lockedConsultation->getKey(),
                'source_orientation_id' => $medicineOrientation->getKey(),
                'care_orientation_id' => $careOrientation->getKey(),
                'requested_by' => $actor->getKey(),
                'instructions' => $instructions,
                'requires_return_to_medicine' => $requiresReturnToMedicine,
                'status' => CareOrderStatus::Pending,
                'ordered_at' => now(),
            ]);

            foreach ($items as $item) {
                $catalogItem = $catalogItems->get($item['catalog_item_uuid']);

                $careOrder->items()->create([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'catalog_item_code_snapshot' => $catalogItem->code,
                    'catalog_item_name_snapshot' => $catalogItem->name,
                    'quantity' => $item['quantity'],
                    'instructions' => $item['instructions'] ?? null,
                ]);
            }

            // Explicit action is the trigger, never the reverse: this only
            // synchronizes the field to reflect an order that has, in fact,
            // just been created.
            $lockedConsultation->update(['decision' => ConsultationDecision::NursingCare]);

            return $careOrder->fresh(['items', 'careOrientation']);
        });
    }
}
