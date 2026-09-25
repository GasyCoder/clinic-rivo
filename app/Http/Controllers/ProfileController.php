<?php

namespace App\Http\Controllers;

use App\Actions\User\ChangeOwnPasswordAction;
use App\Http\Requests\UpdateOwnPasswordRequest;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-184 — « Mon profil » : chaque compte lit son identité, son rôle, son
 * profil métier et ses droits, et change son mot de passe. Rien d'autre ne s'y
 * modifie : le nom, l'email, le rôle et les droits restent à l'administrateur
 * (ADR-022). La disposition de la page suit le modèle réglé pour le site.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user()->loadMissing(['role', 'professionalProfile']);
        $names = $user->effectivePermissionNames()->values();
        $labels = Permission::query()->whereIn('name', $names)->pluck('label', 'name');

        return Inertia::render('Profile/Show', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'professional_profile' => $user->professionalProfile?->name,
                'site' => config('rivo.site.type') === 'admin' ? 'Super Administration' : config('rivo.site.name'),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            // Ce que le compte peut réellement faire : socle du rôle et exceptions compris.
            // Jamais sous la clé `permissions` : c'est la prop partagée que lisent le menu
            // latéral et chaque `can()` — l'écraser vidait le menu sur cette page.
            'grantedPermissions' => $names->map(fn (string $name) => [
                'name' => $name,
                'label' => $labels[$name] ?? $name,
            ])->all(),
        ]);
    }

    public function updatePassword(UpdateOwnPasswordRequest $request, ChangeOwnPasswordAction $action): RedirectResponse
    {
        $closed = $action->execute($request->user(), $request->string('password')->toString(), $request->session()->getId());

        return back()->with('status', $closed > 0
            ? "Mot de passe changé. {$closed} autre".($closed > 1 ? 's sessions ouvertes ont été fermées.' : ' session ouverte a été fermée.')
            : 'Mot de passe changé.');
    }
}
