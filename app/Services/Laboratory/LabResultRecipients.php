<?php

namespace App\Services\Laboratory;

use App\Models\LabRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ADR-216 — à qui le technicien peut envoyer des résultats d'analyse.
 *
 * Un destinataire est un compte actif qui peut **prescrire** une analyse et la
 * **lire** (`laboratory_orders.create` et `laboratory_orders.view`) : qui demande
 * des analyses peut en recevoir. Jamais un nom de rôle (ADR-152) — un site qui
 * donne ces droits à une sage-femme la verra dans la liste.
 *
 * La règle est celle de `User::effectivePermissionNames()` (socle du rôle, plus
 * ALLOW, moins DENY), écrite en une requête : la liste se calcule à chaque
 * affichage de la paillasse, qui se recharge à chaque enregistrement.
 */
final class LabResultRecipients
{
    public const PERMISSIONS = ['laboratory_orders.create', 'laboratory_orders.view'];

    /** @return Builder<User> */
    public function query(): Builder
    {
        $query = User::query()->where('active', true)
            ->whereDoesntHave('role', fn (Builder $role) => $role->where('code', 'SUPER_ADMIN'));

        $ids = Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id', 'name');

        foreach (self::PERMISSIONS as $name) {
            $id = $ids[$name] ?? null;

            if ($id === null) {
                // Un droit absent du catalogue n'est détenu par personne.
                return $query->whereRaw('1 = 0');
            }

            $query->where(fn (Builder $holder) => $holder
                ->whereHas('role.permissions', fn (Builder $permission) => $permission->whereKey($id))
                ->orWhereHas('permissions', fn (Builder $permission) => $permission->whereKey($id)->where('user_permissions.effect', 'allow')))
                ->whereDoesntHave('permissions', fn (Builder $permission) => $permission->whereKey($id)->where('user_permissions.effect', 'deny'));
        }

        return $query;
    }

    /** @return Collection<int, User> */
    public function all(): Collection
    {
        return $this->query()->with(['role:id,code,name', 'professionalProfile:id,name'])->orderBy('name')->get();
    }

    public function isRecipient(?User $user): bool
    {
        return $user !== null && $user->getKey() !== null && $this->query()->whereKey($user->getKey())->exists();
    }

    /**
     * Le destinataire proposé d'office : le prescripteur, quand il peut
     * recevoir. Une demande de l'accueil a pour auteur la réceptionniste, qui
     * ne prescrit pas : rien n'est proposé, le technicien choisit.
     */
    public function proposedFor(LabRequest $request): ?User
    {
        if ($request->results_recipient_id !== null) {
            return $request->resultsRecipient;
        }

        $prescriber = $request->requestedBy;

        return $this->isRecipient($prescriber) ? $prescriber : null;
    }

    /**
     * Amendement ADR-216 du 2026-09-29 (ter) — les destinataires cochés à
     * l'ouverture : ceux déjà servis, sinon le prescripteur quand il peut recevoir.
     *
     * @return list<string>
     */
    public function proposedUuidsFor(LabRequest $request): array
    {
        $ids = $request->recipientIds();

        if ($ids !== []) {
            return User::query()->whereIn('id', $ids)->pluck('uuid')->all();
        }

        $prescriber = $this->proposedFor($request);

        return $prescriber ? [$prescriber->uuid] : [];
    }

    /**
     * La liste du choix, le prescripteur en tête quand il y figure.
     *
     * @return list<array{uuid: string, name: string, detail: ?string, prescriber: bool}>
     */
    public function options(LabRequest $request): array
    {
        return $this->all()
            ->map(fn (User $user): array => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'detail' => $user->professionalProfile?->name ?? $user->role?->name,
                'prescriber' => (int) $user->getKey() === (int) $request->requested_by,
            ])
            ->sortByDesc('prescriber')
            ->values()
            ->all();
    }
}
