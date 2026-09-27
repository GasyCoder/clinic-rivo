<?php

namespace App\Services\Surgery;

use App\Models\Employee;
use App\Models\PlanningShift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADR-168 — qui peut être programmé comme chirurgien, et quand.
 *
 * Un chirurgien est un compte actif portant le profil métier SURGEON : ni le
 * rôle seul, ni un nom de fonction. Sa disponibilité se lit sur le planning RH
 * (ADR-066) de la fiche Employé reliée à son compte (`employees.user_id`) :
 *
 *   AVAILABLE        un créneau du planning couvre l'heure programmée
 *   OFF_PLANNING     fiche reliée, aucun créneau à cette heure — refusé
 *   INACTIVE_RECORD  fiche reliée mais inactive ou archivée — refusé
 *   UNLINKED         aucun planning connu pour ce compte — accepté, signalé
 *
 * UNLINKED n'est pas « absent » : sans fiche reliée, le planning ne dit rien,
 * et le refuser bloquerait toute programmation tant que les RH n'ont pas relié
 * les comptes. L'écran le dit en toutes lettres ; la règle se durcit d'elle-même
 * à mesure que les fiches sont reliées (même esprit que l'ADR-164).
 */
class SurgeonRoster
{
    public const PROFILE_CODE = 'SURGEON';

    public const AVAILABLE = 'AVAILABLE';

    public const OFF_PLANNING = 'OFF_PLANNING';

    public const INACTIVE_RECORD = 'INACTIVE_RECORD';

    public const UNLINKED = 'UNLINKED';

    /** @return Collection<int, User> */
    public function surgeons(): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereNull('deactivated_at')
            ->whereHas('professionalProfile', fn ($query) => $query
                ->where('code', self::PROFILE_CODE)
                ->where('active', true))
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'role_id', 'professional_profile_id']);
    }

    public function isSurgeon(?User $user): bool
    {
        if (! $user || ! $user->active || $user->deactivated_at) {
            return false;
        }

        $profile = $user->professionalProfile;

        return $profile !== null && $profile->active && $profile->code === self::PROFILE_CODE;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{state: string, selectable: bool, label: string, shift: ?array{starts_at: string, ends_at: string, title: ?string}}>
     */
    public function availability(Collection $users, CarbonInterface $at): array
    {
        $employees = Employee::withTrashed()
            ->whereIn('user_id', $users->pluck('id'))
            ->with(['planningShifts' => fn ($query) => $query
                ->where('starts_at', '<=', $at)
                ->where('ends_at', '>', $at)
                ->orderBy('starts_at')])
            ->get()
            ->keyBy('user_id');

        return $users->mapWithKeys(fn (User $user) => [
            $user->id => $this->describe($employees->get($user->id), $at),
        ])->all();
    }

    /**
     * Refuse, sous la clé du champ, ce qui ne peut pas être programmé. Toutes
     * les raisons sont renvoyées ensemble : corriger un chirurgien pour
     * découvrir ensuite le refus du suivant ferait perdre du temps.
     *
     * @param  array<string, User>  $selection  clé du champ => compte choisi
     */
    public function assertSchedulable(array $selection, CarbonInterface $at): void
    {
        $users = collect($selection)->values();
        $availability = $this->availability($users, $at);
        $errors = [];

        foreach ($selection as $field => $user) {
            if (! $this->isSurgeon($user)) {
                $errors[$field] = "« {$user->name} » n’a pas le profil Chirurgien : il ne peut pas être programmé comme chirurgien.";

                continue;
            }

            $state = $availability[$user->id];

            if (! $state['selectable']) {
                $errors[$field] = "« {$user->name} » : {$state['label']}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{state: string, selectable: bool, label: string, shift: ?array{starts_at: string, ends_at: string, title: ?string}} */
    private function describe(?Employee $employee, CarbonInterface $at): array
    {
        $when = $at->format('d/m/Y').' à '.$at->format('H:i');

        if (! $employee) {
            return [
                'state' => self::UNLINKED,
                'selectable' => true,
                'label' => 'planning RH non relié — disponibilité non vérifiée',
                'shift' => null,
            ];
        }

        if ($employee->trashed() || ! $employee->active) {
            return [
                'state' => self::INACTIVE_RECORD,
                'selectable' => false,
                'label' => 'fiche RH inactive ou archivée',
                'shift' => null,
            ];
        }

        /** @var PlanningShift|null $shift */
        $shift = $employee->planningShifts->first();

        if (! $shift) {
            return [
                'state' => self::OFF_PLANNING,
                'selectable' => false,
                'label' => "absent du planning RH le {$when}",
                'shift' => null,
            ];
        }

        return [
            'state' => self::AVAILABLE,
            'selectable' => true,
            'label' => 'au planning de '.$shift->starts_at->format('H:i').' à '.$shift->ends_at->format('H:i')
                .($shift->starts_at->isSameDay($shift->ends_at) ? '' : ' (le '.$shift->ends_at->format('d/m').')'),
            'shift' => [
                'starts_at' => $shift->starts_at->toIso8601String(),
                'ends_at' => $shift->ends_at->toIso8601String(),
                'title' => $shift->title,
            ],
        ];
    }
}
