<?php

namespace App\Services\StaffAccess;

use App\Models\StaffAccessNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NewEmployeesAwaitingAccess;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * ADR-197 — portail : prévenir le Super Admin quand le RH d'un site ajoute des
 * employés qui attendent leur accès.
 *
 * Les sites ne parlent pas au portail (ADR-004) : c'est le portail qui relit leur
 * API — à l'ouverture de la cloche ou des pages concernées (après la réponse, au
 * plus toutes les deux minutes), et par la tâche planifiée. Chaque employé n'est
 * signalé qu'une fois (`staff_access_notices`, unique par site et employé), même
 * si deux lectures se croisent.
 */
final class StaffAccessWatcher
{
    public const PERMISSION = 'staff_access.view';

    private const THROTTLE_KEY = 'staff-access.sync-throttle';

    private const COUNTS_KEY = 'staff-access.pending-counts';

    private const THROTTLE_SECONDS = 120;

    public function __construct(private readonly PortalSiteApiClient $sites) {}

    /** Relit les sites après la réponse, au plus une fois par période : la cloche n'attend jamais. */
    public function syncSoon(User $actor): void
    {
        if (! Cache::add(self::THROTTLE_KEY, true, self::THROTTLE_SECONDS)) {
            return;
        }

        $id = $actor->getKey();
        dispatch(function () use ($id): void {
            $actor = User::query()->find($id);

            if ($actor !== null) {
                app(self::class)->sync($actor);
            }
        })->afterResponse();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $results  les lectures déjà faites par la page, sinon relues ici
     * @param  User|null  $viewer  le compte qui regarde déjà la liste : il n'a pas besoin d'être prévenu
     * @return int les sites pour lesquels une notification est partie
     */
    public function sync(User $actor, ?array $results = null, ?User $viewer = null): int
    {
        $results ??= $this->sites->staffAccessForAllSites($actor);
        $counts = Cache::get(self::COUNTS_KEY, []);
        $notified = 0;

        foreach ($results as $result) {
            $code = (string) ($result['site']['code'] ?? '');
            $name = (string) ($result['site']['name'] ?? $code);

            if ($code === '' || ! ($result['ok'] ?? false) || ! is_array($result['data']['pending'] ?? null)) {
                continue;
            }

            $pending = collect($result['data']['pending'])->filter(fn ($row) => is_array($row) && is_string($row['uuid'] ?? null))->values();
            $counts[$code] = $pending->count();

            // ADR-199 — une notification dont les employés sont servis ne les annonce plus.
            $this->resolve($code, $pending->pluck('uuid')->all());

            // Seuls ceux que cette lecture signale vraiment en premier (l'index unique tranche).
            $new = $pending->filter(fn (array $row) => StaffAccessNotice::query()->insertOrIgnore([
                'site_code' => $code,
                'employee_uuid' => $row['uuid'],
                'noticed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0)->values();

            if ($new->isEmpty()) {
                continue;
            }

            $recipients = self::recipients()->reject(fn (User $user) => $viewer !== null && $user->is($viewer));
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new NewEmployeesAwaitingAccess(
                    $code,
                    $name,
                    $new->pluck('name')->map(fn ($value) => (string) $value)->all(),
                    $new->count(),
                    $new->pluck('uuid')->map(fn ($value) => (string) $value)->all(),
                ));
                $notified++;
            }
        }

        Cache::put(self::COUNTS_KEY, $counts, now()->addDay());

        return $notified;
    }

    /**
     * ADR-199 — met à jour les notifications « des employés attendent leur accès »
     * d'un site, d'après ceux qui attendent encore à cette lecture : combien restent
     * (`meta.waiting`), et « Traité » (`meta.resolved_at`, lue) quand plus aucun
     * n'attend — compte créé, ici ou au site, « aucun accès nécessaire », départ.
     * Une notification d'avant cette règle ne sait pas quels employés elle
     * annonçait : elle n'est traitée que quand plus personne n'attend sur le site.
     *
     * @param  list<string>  $pendingUuids
     * @return int les notifications modifiées
     */
    public function resolve(string $siteCode, array $pendingUuids): int
    {
        $pending = array_flip($pendingUuids);
        $changed = 0;

        UserNotification::query()
            ->where('type', NewEmployeesAwaitingAccess::class)
            ->where('created_at', '>=', now()->subDays(90))
            ->get()
            ->each(function (UserNotification $notification) use ($siteCode, $pending, &$changed): void {
                $data = $notification->data ?? [];
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

                if (($meta['site_code'] ?? null) !== $siteCode || isset($meta['resolved_at'])) {
                    return;
                }

                $uuids = array_values(array_filter((array) ($meta['employee_uuids'] ?? []), 'is_string'));
                if ($uuids === []) {
                    $uuids = self::announcedBy($notification, $siteCode, (int) ($meta['count'] ?? 0));
                    $meta['employee_uuids'] = $uuids;
                }
                if ($uuids === [] && $pending !== []) {
                    return;
                }

                $waiting = count(array_filter($uuids, fn (string $uuid) => isset($pending[$uuid])));
                if ($waiting === ($meta['waiting'] ?? null) && $waiting > 0) {
                    return;
                }

                $meta['waiting'] = $waiting;
                if ($waiting === 0) {
                    $meta['resolved_at'] = now()->toIso8601String();
                    $notification->read_at ??= now();
                }

                $data['meta'] = $meta;
                $notification->data = $data;
                $notification->save();
                $changed++;
            });

        return $changed;
    }

    /**
     * Une notification d'avant l'ADR-199 ne gardait pas ses employés : ce sont ceux
     * que la même lecture a relevés (`staff_access_notices`, à la minute près), et
     * seulement si leur nombre est exactement celui qu'elle annonçait — sinon rien
     * n'est deviné.
     *
     * @return list<string>
     */
    private static function announcedBy(UserNotification $notification, string $siteCode, int $count): array
    {
        if ($count < 1 || $notification->created_at === null) {
            return [];
        }

        $uuids = StaffAccessNotice::query()
            ->where('site_code', $siteCode)
            ->whereBetween('noticed_at', [$notification->created_at->copy()->subMinute(), $notification->created_at->copy()->addMinute()])
            ->pluck('employee_uuid')
            ->all();

        return count($uuids) === $count ? array_values($uuids) : [];
    }

    /** @return array<string, int> Les employés en attente par site, à la dernière lecture. */
    public static function pendingCounts(): array
    {
        $counts = Cache::get(self::COUNTS_KEY, []);

        return is_array($counts) ? array_map('intval', $counts) : [];
    }

    /** @return Collection<int, User> Les comptes du portail qui créent les accès. */
    public static function recipients(): Collection
    {
        return User::query()->where('active', true)->get()->filter(fn (User $user) => $user->can(self::PERMISSION))->values();
    }
}
