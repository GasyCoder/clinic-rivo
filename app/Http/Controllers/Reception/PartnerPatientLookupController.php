<?php

namespace App\Http\Controllers\Reception;

use App\Enums\PartnerCategory;
use App\Http\Controllers\Controller;
use App\Models\PartnerOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-211 — l'accueil retrouve un partenaire médical venu se faire soigner.
 *
 * Seules les fiches Médical sont proposées : ce sont des personnes, qui
 * peuvent devenir patient. Un partenaire « Autre » (une école, une entreprise)
 * n'est jamais le patient ; il se choisit à la prise en charge du passage.
 *
 * La fiche sert à préremplir le nouveau dossier patient ; si la personne en a
 * déjà un, il est désigné tel quel. L'adresse est celle que la fiche porte dans
 * le référentiel du site ; une adresse archivée depuis n'est pas reprise (le
 * dossier patient ne l'accepterait plus).
 */
class PartnerPatientLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('episodes.create'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $search = trim($validated['q']);

        $partners = PartnerOrganization::query()
            ->with(['patient:id,uuid,patient_number,first_name,last_name,deleted_at', 'addressEntry:id,uuid,label,active,deleted_at'])
            ->active()
            ->where('category', PartnerCategory::Medical->value)
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json(['data' => $partners->map(fn (PartnerOrganization $partner) => [
            'uuid' => $partner->uuid,
            'name' => $partner->name,
            'last_name' => $partner->last_name,
            'first_name' => $partner->first_name,
            'profession_label' => $partner->professionLabel(),
            'sex' => $partner->sex?->value,
            'birth_date' => $partner->birth_date?->toDateString(),
            'phone' => $partner->phone,
            'email' => $partner->email,
            'address' => $partner->address,
            'address_entry_uuid' => $partner->addressEntry?->active && ! $partner->addressEntry->trashed()
                ? $partner->addressEntry->uuid
                : null,
            'linked_patient' => $partner->patient && ! $partner->patient->trashed() ? [
                'uuid' => $partner->patient->uuid,
                'patient_number' => $partner->patient->patient_number,
                'first_name' => $partner->patient->first_name,
                'last_name' => $partner->patient->last_name,
            ] : null,
            'linked_patient_archived' => (bool) $partner->patient?->trashed(),
        ])->values()]);
    }
}
