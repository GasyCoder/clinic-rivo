<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\ReceiveLabRequestAction;
use App\Actions\Laboratory\RecordLabSamplesAction;
use App\Actions\Laboratory\RejectLabSampleAction;
use App\Actions\Laboratory\SaveLabConclusionAction;
use App\Actions\Laboratory\SendOutLabItemAction;
use App\Http\Requests\Laboratory\ReceiveLabRequestRequest;
use App\Http\Requests\Laboratory\SendOutLabItemRequest;
use App\Http\Requests\Laboratory\StoreLabSamplesRequest;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabSample;
use App\Services\Laboratory\LabRequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-214 — ce qui se passe au guichet du laboratoire et autour de la
 * paillasse : réception (contrôle du règlement), prélèvements et leurs
 * étiquettes, non-conformité, envoi à un laboratoire extérieur et son bon,
 * conclusion générale du biologiste. Le Laboratoire n'encaisse rien.
 */
class LabReceptionController extends Controller
{
    public function receive(ReceiveLabRequestRequest $request, LabRequest $labRequest, ReceiveLabRequestAction $action): RedirectResponse
    {
        $received = $action->execute($labRequest, $request->validated('samples') ?? [], $request->user());
        $count = $received->samples()->count();

        return back()->with('status', "Demande prise en charge sous le n° {$received->lab_number}"
            .($count > 0 ? " — {$count} prélèvement(s) enregistré(s) : imprimez les étiquettes." : '.'));
    }

    /**
     * ADR-217 — « Traiter » depuis la file : la demande est prise en charge
     * (numéro de laboratoire, technicien), quel que soit le règlement, puis la
     * paillasse s'ouvre. Idempotent : une demande déjà commencée s'ouvre.
     */
    public function start(Request $request, LabRequest $labRequest, ReceiveLabRequestAction $action): RedirectResponse
    {
        $started = $action->start($labRequest, $request->user());

        return redirect("/laboratory/requests/{$started->uuid}")
            ->with('status', "Demande {$started->lab_number} prise en charge : saisissez les résultats.");
    }

    public function storeSamples(StoreLabSamplesRequest $request, LabRequest $labRequest, RecordLabSamplesAction $action): RedirectResponse
    {
        $samples = $action->execute($labRequest, $request->validated('samples'), $request->user());

        return back()->with('status', $samples->count().' prélèvement(s) enregistré(s) : imprimez leurs étiquettes.');
    }

    public function rejectSample(Request $request, LabSample $labSample, RejectLabSampleAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => 'Indiquez pourquoi le prélèvement n’est pas conforme.',
            'reason.min' => 'Indiquez pourquoi le prélèvement n’est pas conforme.',
        ]);
        $sample = $action->execute($labSample, $validated['reason'], $request->user());

        return back()->with('status', "Prélèvement {$sample->barcode} déclaré non conforme : enregistrez-en un autre.");
    }

    public function labels(Request $request, LabRequest $labRequest, LabRequestPresenter $presenter): Response
    {
        abort_if($labRequest->received_at === null, 404);
        $wanted = array_filter((array) $request->query('samples', []), 'is_string');

        $samples = collect($presenter->samples($labRequest))
            ->whereNull('rejected')
            ->when($wanted !== [], fn ($samples) => $samples->whereIn('uuid', $wanted))
            ->values();

        return Inertia::render('Laboratory/Labels', [
            'labRequest' => $presenter->header($labRequest),
            'samples' => $samples,
        ]);
    }

    public function sendOut(SendOutLabItemRequest $request, LabRequestItem $labRequestItem, SendOutLabItemAction $action): RedirectResponse
    {
        $item = $action->execute($labRequestItem, $request->validated(), $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » confiée à {$item->external_lab_name} : imprimez le bon d’envoi.");
    }

    public function cancelSendOut(Request $request, LabRequestItem $labRequestItem, SendOutLabItemAction $action): RedirectResponse
    {
        $item = $action->cancel($labRequestItem, $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » se fait de nouveau au laboratoire de la clinique.");
    }

    public function sendOutSlip(LabRequest $labRequest, LabRequestPresenter $presenter): Response
    {
        $labRequest->load(['items.sentOutBy:id,name', 'samples']);
        $items = $labRequest->items->whereNotNull('sent_out_at')->sortBy('id');
        abort_if($items->isEmpty(), 404);

        return Inertia::render('Laboratory/SendOutSlip', [
            'labRequest' => $presenter->header($labRequest),
            'samples' => collect($presenter->samples($labRequest))->whereNull('rejected')->values(),
            'groups' => $items->groupBy('external_lab_name')->map(fn ($group, string $laboratory) => [
                'laboratory' => $laboratory,
                'items' => $group->map(fn (LabRequestItem $item) => [
                    'uuid' => $item->uuid,
                    'name' => $item->catalog_item_name_snapshot,
                    'code' => $item->catalog_item_code_snapshot,
                    'reference' => $item->external_reference,
                    'notes' => $item->sent_out_notes,
                    'sent_out_at' => $item->sent_out_at,
                    'sent_out_by' => $item->sentOutBy?->name,
                ])->values(),
            ])->values(),
        ]);
    }

    public function conclusion(Request $request, LabRequest $labRequest, SaveLabConclusionAction $action): RedirectResponse
    {
        $validated = $request->validate(['conclusion' => ['nullable', 'string', 'max:3000']]);
        $saved = $action->execute($labRequest, $validated['conclusion'] ?? null, $request->user());

        return back()->with('status', $saved->conclusion ? 'Conclusion générale enregistrée.' : 'Conclusion générale retirée.');
    }
}
