<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Services\Laboratory\LabResultReport;
use App\Services\Laboratory\ValidatedLabResults;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * ADR-216, amendement quater — « Résultats à remettre » : la Réception voit les
 * résultats d'analyses que le médecin a validés, et imprime leur compte rendu
 * pour le patient. La liste lit ; elle ne valide, ne modifie ni ne renvoie rien.
 *
 * Droit : `laboratory_results.validated_view` (socle RECEPTION), réglé depuis
 * « Rôles & permissions ». Aucune valeur non validée n'est servie.
 */
class LabResultHandoverController extends Controller
{
    private const VIEWS = ['complets', 'partiels', 'tous'];

    public function index(Request $request): Response
    {
        $view = in_array($request->query('vue'), self::VIEWS, true) ? $request->query('vue') : 'tous';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $like = '%'.addcslashes($search, '%_\\').'%';
        $canViewPatients = $request->user()->can('patients.view');

        $base = fn (): Builder => ValidatedLabResults::requests()
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('lab_number', 'like', $like)
                ->orWhereHas('episode', fn ($episode) => $episode
                    ->where('episode_number', 'like', $like)
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('patient_number', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)))));

        $counts = [
            'tous' => $base()->count(),
            'complets' => ValidatedLabResults::complete($base())->count(),
        ];
        $counts['partiels'] = $counts['tous'] - $counts['complets'];

        $query = $base();
        if ($view === 'complets') {
            ValidatedLabResults::complete($query);
        } elseif ($view === 'partiels') {
            $query->whereHas('items', fn ($items) => $items->where(fn ($pending) => $pending
                ->whereNull('approved_at')->orWhereNull('sent_at')->orWhere('status', '!=', 'VALIDATED')));
        }

        $requests = $query
            ->with([
                'episode:id,uuid,episode_number,patient_id',
                'episode.patient:id,uuid,patient_number,first_name,last_name',
                'requestedBy:id,name',
                'items' => fn ($items) => $items->orderBy('id'),
                'items.approvedBy:id,name',
            ])
            ->orderByDesc(LabRequestItem::query()->selectRaw('max(approved_at)')->whereColumn('lab_request_id', 'lab_requests.id'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LabRequest $labRequest) => $this->row($labRequest, $canViewPatients));

        return Inertia::render('Reception/LabResults/Index', [
            'requests' => $requests,
            'counts' => $counts,
            'filters' => ['vue' => $view, 'q' => $search],
        ]);
    }

    /** Le compte rendu PDF, réduit à ce que le médecin a validé. */
    public function pdf(Request $request, LabRequest $labRequest, LabResultReport $report): HttpResponse
    {
        abort_if($labRequest->cancelled_at !== null, 404);
        $items = $labRequest->items()->orderBy('id')->get()->filter(fn (LabRequestItem $item) => $item->isApproved());
        abort_if($items->isEmpty(), 404, 'Aucun résultat de cette demande n’est encore validé par le médecin.');

        $filename = $report->filename($labRequest);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return response($report->render($report->compose($labRequest, $items, approvedOnly: true)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(LabRequest $labRequest, bool $canViewPatients): array
    {
        $patient = $labRequest->episode?->patient;
        [$approved, $pending] = $labRequest->items->partition(fn (LabRequestItem $item) => $item->isApproved());

        return [
            'uuid' => $labRequest->uuid,
            'lab_number' => $labRequest->lab_number,
            'requested_at' => $labRequest->requested_at?->toIso8601String(),
            'prescriber' => $labRequest->requestedBy?->name,
            'episode' => $labRequest->episode ? [
                'uuid' => $canViewPatients ? $labRequest->episode->uuid : null,
                'number' => $labRequest->episode->episode_number,
            ] : null,
            'patient' => $patient ? [
                'uuid' => $canViewPatients ? $patient->uuid : null,
                'number' => $patient->patient_number,
                'name' => trim("{$patient->last_name} {$patient->first_name}"),
            ] : null,
            'approved' => $approved->map(fn (LabRequestItem $item) => [
                'uuid' => $item->uuid,
                'name' => $item->catalog_item_name_snapshot,
                'approved_at' => $item->approved_at?->toIso8601String(),
                'approved_by' => $item->approvedBy?->name,
            ])->values(),
            'pending' => $pending->map(fn (LabRequestItem $item) => [
                'uuid' => $item->uuid,
                'name' => $item->catalog_item_name_snapshot,
                'state' => ValidatedLabResults::pendingLabel($item),
            ])->values(),
            'complete' => $pending->isEmpty(),
            'approved_at' => $approved->max('approved_at')?->toIso8601String(),
            'approved_by' => $approved->map(fn (LabRequestItem $item) => $item->approvedBy?->name)->filter()->unique()->values(),
            'pdf_url' => "/reception/resultats-analyses/{$labRequest->uuid}/pdf",
        ];
    }
}
