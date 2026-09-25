<?php

namespace App\Http\Controllers;

use App\Actions\User\ChangeOwnPasswordAction;
use App\Http\Requests\UpdateOwnPasswordRequest;
use App\Models\Permission;
use App\Support\Settings\UiOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /**
     * ADR-191 — la taille du texte, les animations et le contraste de ce compte.
     * Une valeur vide reprend celle du site. Gardé sur le compte, pas sur le poste :
     * un poste est partagé, et les réglages d'une personne ne suivent pas la suivante.
     */
    public function updateAppearance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'font_size' => ['nullable', 'integer', Rule::in(UiOptions::FONT_SIZES)],
            'motion' => ['nullable', Rule::in(UiOptions::MOTIONS)],
            'contrast' => ['nullable', Rule::in(UiOptions::CONTRASTS)],
        ], [
            'font_size.in' => 'Choisissez une taille proposée.',
            'motion.in' => 'Choisissez un réglage d’animation proposé.',
            'contrast.in' => 'Choisissez un niveau de contraste proposé.',
        ]);

        $preferences = [];
        foreach (UiOptions::PERSONAL as $key) {
            if (($value = UiOptions::clean($key, $data[$key] ?? null)) !== null) {
                $preferences[$key] = $value;
            }
        }

        $request->user()->forceFill(['ui_preferences' => $preferences ?: null])->save();

        return back()->with('status', $preferences ? 'Apparence enregistrée.' : 'Apparence du site rétablie.');
    }

    public function updatePassword(UpdateOwnPasswordRequest $request, ChangeOwnPasswordAction $action): RedirectResponse
    {
        $closed = $action->execute($request->user(), $request->string('password')->toString(), $request->session()->getId());

        return back()->with('status', $closed > 0
            ? "Mot de passe changé. {$closed} autre".($closed > 1 ? 's sessions ouvertes ont été fermées.' : ' session ouverte a été fermée.')
            : 'Mot de passe changé.');
    }
}
