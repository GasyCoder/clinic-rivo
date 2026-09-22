<?php

namespace App\Actions\Care;

use App\Actions\Billing\AttachBillableItemToUnpaidInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\CareConsumableRequest;
use App\Models\CareConsumableRequestLine;
use App\Models\CareRecord;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Care\CareConsumableDirectory;
use App\Services\Finance\FinancialNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072 — Soins declares the consumables it actually used on the patient
 * and Pharmacy is notified so the stock exit is recorded by the module that
 * owns the stock. ADR-142 — la Maternité utilise le même circuit, avec une liste
 * de produits élargie au matériel configuré pour ses actes. ADR-169 — le bloc
 * aussi, avec la même règle pour les actes de Chirurgie.
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

            // Le service demandeur se lit sur l'orientation, jamais sur une valeur
            // envoyée : un compte ne se fait pas passer pour l'autre service.
            $service = $orientation->destination_module === CatalogModule::Maternity
                ? CatalogModule::Maternity
                : CatalogModule::Care;

            return $this->record($episode, $service, [
                // L'orientation du service demandeur — Soins ou Maternité : c'est le
                // lien que la file, la facturation et la sortie de stock lisent.
                'care_orientation_id' => $orientation->getKey(),
                'care_record_id' => $service === CatalogModule::Care
                    ? ($record?->getKey() ?? $episode->careRecord?->getKey())
                    : null,
                'maternity_record_id' => $service === CatalogModule::Maternity
                    ? $episode->maternityRecord?->getKey()
                    : null,
            ], $data, $actor);
        });
    }

    /**
     * ADR-169 — le matériel utilisé au bloc, déclaré depuis le dossier de
     * l'intervention. La demande désigne ce dossier ; l'orientation vers le bloc
     * est reprise quand le passage en a une, jamais inventée (une demande
     * ouverte au bloc avant l'ADR-159 n'en a pas).
     *
     * @param  array<string, mixed>  $data
     */
    public function executeForSurgery(SurgicalRequest $surgicalRequest, array $data, User $actor): CareConsumableRequest
    {
        return DB::transaction(function () use ($surgicalRequest, $data, $actor): CareConsumableRequest {
            $surgicalRequest = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());

            if ($surgicalRequest->status === SurgicalRequestStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'lines' => 'Cette demande de chirurgie est annulée : aucun matériel ne peut plus y être déclaré.',
                ]);
            }

            $episode = $surgicalRequest->episode;
            $orientation = $episode->orientations()
                ->where('destination_module', CatalogModule::Surgery->value)
                ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
                ->latest('id')
                ->first();

            return $this->record($episode, CatalogModule::Surgery, [
                'care_orientation_id' => $orientation?->getKey(),
                'surgical_request_id' => $surgicalRequest->getKey(),
            ], $data, $actor);
        });
    }

    /**
     * L'écriture commune aux trois services : contrôle des produits, demande,
     * lignes figées, facturation de chaque ligne, audit. Appelée dans la
     * transaction de son point d'entrée.
     *
     * @param  array<string, int|null>  $links
     * @param  array<string, mixed>  $data
     */
    private function record(Episode $episode, CatalogModule $service, array $links, array $data, User $actor): CareConsumableRequest
    {
        if ($episode->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages([
                'lines' => 'Ce passage est clôturé : aucun consommable ne peut plus y être déclaré.',
            ]);
        }

        $submitted = collect($data['lines'])->keyBy('medicine_uuid');
        $medicines = CareConsumableDirectory::eligibleMedicines($service)
            ->whereIn('uuid', $submitted->keys())
            ->with('catalogItem:id,uuid,code,name,unit,billable')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('uuid');

        if ($medicines->count() !== $submitted->count()) {
            throw ValidationException::withMessages([
                'lines' => match ($service) {
                    CatalogModule::Maternity => 'Seuls les consommables de parapharmacie et le matériel configuré pour un acte de la Maternité peuvent être déclarés. Une ordonnance relève exclusivement de la Médecine et de la Pharmacie.',
                    CatalogModule::Surgery => 'Seuls les consommables de parapharmacie et le matériel configuré pour un acte de Chirurgie peuvent être déclarés depuis le bloc. Un produit absent de cette liste se note « hors stock ».',
                    default => 'Seuls les consommables de parapharmacie actifs peuvent être déclarés par les Soins. Un médicament ou une ordonnance relèvent exclusivement de la Médecine et de la Pharmacie.',
                },
            ]);
        }

        $request = CareConsumableRequest::query()->create([
            'request_number' => $this->numbers->careConsumableRequest(),
            'source_module' => $service->value,
            'episode_id' => $episode->getKey(),
            ...$links,
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

        $module = match ($service) {
            CatalogModule::Maternity => 'maternity',
            CatalogModule::Surgery => 'surgery',
            default => 'care',
        };

        $this->auditor->record(
            "{$module}.consumables.request",
            entity: $request,
            newValues: [
                'request_number' => $request->request_number,
                'episode_uuid' => $episode->uuid,
                'lines' => $request->lines()->count(),
            ],
            module: $module,
            actor: $actor,
        );

        return $request->load(['lines.medicine.catalogItem', 'requester:id,name']);
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
