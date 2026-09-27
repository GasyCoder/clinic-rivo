<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PartnerOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ADR-212 — qui a recommandé la clinique : l'accueil cherche parmi le
 * personnel en poste et les partenaires actifs. Seul ce qui sert à reconnaître
 * la personne est servi (nom, matricule, fonction ou métier) — jamais sa
 * naissance, son adresse ni sa pièce d'identité.
 */
class ReferrerLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $search = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        // Chaque mot doit se retrouver quelque part : « Rakoto Jean » trouve RAKOTO Jean.
        $terms = collect(preg_split('/\s+/u', $search) ?: [])->filter()->take(4)
            ->map(fn (string $term) => '%'.addcslashes($term, '%_\\').'%');

        $employees = Employee::query()
            ->with('jobTitle:id,label')
            ->where('active', true)
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->where(fn ($nested) => $nested
                        ->where('employee_number', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('first_name', 'like', $term));
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(8)
            ->get()
            ->map(fn (Employee $employee) => [
                'source' => 'EMPLOYEE',
                'uuid' => $employee->uuid,
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'detail' => collect([$employee->employee_number, $employee->jobTitle?->label ?? $employee->profession])->filter()->implode(' · '),
            ]);

        $partners = PartnerOrganization::query()
            ->active()
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->where(fn ($nested) => $nested->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                }
            })
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (PartnerOrganization $partner) => [
                'source' => 'PARTNER',
                'uuid' => $partner->uuid,
                'name' => $partner->name,
                'detail' => collect([$partner->category?->label(), $partner->professionLabel()])->filter()->implode(' · '),
            ]);

        return response()->json(['data' => $employees->concat($partners)->values()]);
    }
}
