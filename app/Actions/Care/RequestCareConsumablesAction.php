<?php

namespace App\Actions\Care;

use App\Actions\Billing\AttachBillableItemToUnpaidInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MedicineForm;
use App\Models\CareConsumableRequest;
use App\Models\CareConsumableRequestLine;
use App\Models\CareRecord;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072 — Soins declares the consumables it actually used on the patient
 * and Pharmacy is notified so the stock exit is recorded by the module that
 * owns the stock.
 *
 * Two hard limits are enforced here, not in Vue:
 *  - only MedicineForm::ParapharmacyConsumable products are accepted, which
 *    is what makes "Soins may never give a medicine or a prescription"
 *    (client requirement, 2026-09-10) a server-side rule rather than a UI
 *    convention;
 *  - Soins never prices anything: the tariff is resolved server-side by
 *    RecordBillableItemAction, exactly like a nursing act (ADR-054).
 */
class RequestCareConsumablesAction
{
    public function __construct(
        private readonly RecordBillableItemAction $recordBillableItem,
        private readonly AttachBillableItemToUnpaidInvoiceAction $attachToUnpaidInvoice,
        private readonly FinancialNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        EpisodeOrientation $orientation,
        array $data,
        User $actor,
        ?CareRecord $record = null,
    ): CareConsumableRequest {
        return DB::transaction(function () use ($orientation, $data, $actor, $record): CareConsumableRequest {
            $episode = $orientation->episode;

            if ($episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'lines' => 'Ce passage est clôturé : aucun consommable ne peut plus y être déclaré.',
                ]);
            }

            $submitted = collect($data['lines'])->keyBy('medicine_uuid');
            $medicines = Medicine::query()
                ->whereIn('uuid', $submitted->keys())
                ->where('active', true)
                ->where('form', MedicineForm::ParapharmacyConsumable->value)
                ->whereHas('catalogItem', fn ($query) => $query
                    ->where('type', CatalogItemType::Medicine->value)
                    ->where('module', CatalogModule::Pharmacy->value)
                    ->where('stockable', true))
                ->with('catalogItem:id,uuid,code,name,unit,billable')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($medicines->count() !== $submitted->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Seuls les consommables de parapharmacie actifs peuvent être déclarés par les Soins. Un médicament ou une ordonnance relèvent exclusivement de la Médecine et de la Pharmacie.',
                ]);
            }

            $request = CareConsumableRequest::query()->create([
                'request_number' => $this->numbers->careConsumableRequest(),
                'episode_id' => $episode->getKey(),
                'care_orientation_id' => $orientation->getKey(),
                'care_record_id' => $record?->getKey() ?? $episode->careRecord?->getKey(),
                'status' => CareConsumableRequestStatus::Pending,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'requested_at' => now(),
                'requested_by' => $actor->getKey(),
            ]);

            foreach ($data['lines'] as $submittedLine) {
                $medicine = $medicines->get($submittedLine['medicine_uuid']);
                $line = $request->lines()->create([
                    'medicine_id' => $medicine->getKey(),
                    'medicine_name' => $medicine->catalogItem->name,
                    'medicine_code' => $medicine->catalogItem->code,
                    'unit' => $medicine->catalogItem->unit,
                    'quantity_requested' => (int) $submittedLine['quantity'],
                    'quantity_served' => 0,
                ]);

                $this->billLineIfPossible($request, $line, $medicine, $actor);
            }

            $this->auditor->record(
                'care.consumables.request',
                entity: $request,
                newValues: [
                    'request_number' => $request->request_number,
                    'episode_uuid' => $episode->uuid,
                    'lines' => $request->lines()->count(),
                ],
                module: 'care',
                actor: $actor,
            );

            return $request->load(['lines.medicine.catalogItem', 'requester:id,name']);
        });
    }

    /**
     * The consumable is charged to the patient separately from the nursing
     * act (client decision, 2026-09-10) — on the same passage invoice when
     * one is still uncashed, exactly like an extra nursing act (ADR-054).
     *
     * A billing failure never cancels the declaration or the Pharmacy
     * notification: the consumable is already on the patient's wound. It is
     * left for Réception to regularize, the same rule that already governs
     * every clinical act in this application.
     */
    private function billLineIfPossible(
        CareConsumableRequest $request,
        CareConsumableRequestLine $line,
        Medicine $medicine,
        User $actor,
    ): void {
        if (! $medicine->catalogItem->billable) {
            return;
        }

        try {
            $billableItem = $this->recordBillableItem->execute($request->episode, [
                'catalog_item_uuid' => $medicine->catalogItem->uuid,
                'quantity' => $line->quantity_requested,
                'idempotency_key' => 'care_consumable:'.$line->uuid,
            ], $actor, $line);
        } catch (ValidationException) {
            // No sale price configured, financial context still pending,
            // unclassified Personnel policy… — Réception regularizes later.
            return;
        }

        $line->update(['billable_item_id' => $billableItem->getKey()]);

        if ($billableItem->status !== BillableItemStatus::Pending) {
            return;
        }

        $unpaidInvoice = Invoice::query()
            ->where('episode_id', $request->episode_id)
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Validated->value])
            ->where('paid_amount', 0)
            ->latest()
            ->first();

        if ($unpaidInvoice) {
            $this->attachToUnpaidInvoice->execute($unpaidInvoice, $billableItem);
        }
    }
}
