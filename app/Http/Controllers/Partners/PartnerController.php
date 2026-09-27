<?php

namespace App\Http\Controllers\Partners;

use App\Actions\Partners\ArchivePartnerAction;
use App\Actions\Partners\RestorePartnerAction;
use App\Actions\Partners\SavePartnerAction;
use App\Enums\PartnerCategory;
use App\Enums\PartnerProfession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partners\ArchivePartnerRequest;
use App\Http\Requests\Partners\PartnerRequest;
use App\Models\AddressEntry;
use App\Models\PartnerOrganization;
use App\Support\Partners\PartnerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-211 — le module Partenaires d'un site.
 *
 * Servi deux fois par `routes/partners.php` : sur le site (`/partenaires`) et
 * au Super Admin par l'API du site (`/api/v1/super-admin/site-partners`),
 * relayée par le portail comme les RH et la Pharmacie (ADR-187, ADR-189).
 * L'écran ne décide rien : chaque geste est revérifié par son action.
 */
class PartnerController extends Controller
{
    public function index(Request $request): Response
    {
        $canViewPatients = $request->user()->can('patients.view');

        $partners = PartnerOrganization::withTrashed()
            ->with(['patient:id,uuid,patient_number,first_name,last_name,deleted_at', 'addressEntry:id,uuid,label'])
            ->withCount('episodeCoverages')
            ->orderBy('name')
            ->get();

        return Inertia::render('Partners/Index', [
            'partners' => $partners
                ->map(fn (PartnerOrganization $partner) => PartnerPresenter::row($partner, $canViewPatients))
                ->values(),
            'addresses' => $this->addresses($request, $partners->pluck('address_entry_id')->filter()->unique()->values()->all()),
            'categories' => PartnerCategory::options(),
            'professions' => PartnerProfession::options(),
        ]);
    }

    /**
     * Le référentiel d'adresses du site (ADR-042), servi seulement avec son droit.
     * Une adresse archivée qu'une fiche porte déjà reste affichée — marquée,
     * non proposée — pour que la corriger ne l'efface pas en silence.
     *
     * @param  array<int, int>  $usedIds
     * @return array<int, array{uuid: string, label: string, available: bool}>
     */
    private function addresses(Request $request, array $usedIds): array
    {
        if (! $request->user()->can('address_entries.view')) {
            return [];
        }

        return AddressEntry::withTrashed()
            ->where(fn ($query) => $query
                ->where(fn ($active) => $active->where('active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', $usedIds))
            ->orderBy('label')
            ->get(['id', 'uuid', 'label', 'active', 'deleted_at'])
            ->map(fn (AddressEntry $address) => [
                'uuid' => $address->uuid,
                'label' => $address->label,
                'available' => $address->active && ! $address->trashed(),
            ])
            ->values()
            ->all();
    }

    public function store(PartnerRequest $request, SavePartnerAction $action): RedirectResponse
    {
        $partner = $action->execute(null, $request->validated(), $request->user());

        return back()->with('status', "Partenaire « {$partner->name} » ajouté.");
    }

    public function update(PartnerRequest $request, PartnerOrganization $partner, SavePartnerAction $action): RedirectResponse
    {
        $partner = $action->execute($partner, $request->validated(), $request->user());

        return back()->with('status', "Partenaire « {$partner->name} » mis à jour.");
    }

    public function destroy(ArchivePartnerRequest $request, PartnerOrganization $partner, ArchivePartnerAction $action): RedirectResponse
    {
        $action->execute($partner, $request->validated('reason'), $request->user());

        return back()->with('status', "Partenaire « {$partner->name} » archivé.");
    }

    public function restore(Request $request, PartnerOrganization $partner, RestorePartnerAction $action): RedirectResponse
    {
        $action->execute($partner, $request->user());

        return back()->with('status', "Partenaire « {$partner->name} » restauré.");
    }
}
