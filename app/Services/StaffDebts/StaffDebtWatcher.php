<?php

namespace App\Services\StaffDebts;

use App\Models\StaffDebtNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\StaffDebtRequested;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * ADR-228 — portail : prévenir le DG quand un membre du personnel d'un site demande une
 * dette. Les sites ne parlent pas au portail (ADR-004) : le portail relit leur API — à
 * l'ouverture de la cloche (après la réponse, au plus toutes les deux minutes) et par la
 * tâche planifiée — comme pour l'accès du personnel (ADR-197). Chaque demande n'est
 * signalée qu'une fois (`staff_debt_notices`) ; décidée ou retirée, sa notification se
 * marque « Traité ».
 */
final class StaffDebtWatcher
{
    public const PERMISSION = 'staff_debts.decide';

    private const THROTTLE_KEY = 'staff-debts.sync-throttle';

    private const COUNTS_KEY = 'staff-debts.pending-counts';

    private const DISBURSE_KEY = 'staff-debts.to-disburse-counts';

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
     * @param  array<int, array<string, mixed>>|null  $results  les lectures déjà faites, sinon relues ici
     * @return int les demandes signalées
     */
    public function sync(User $actor, ?array $results = null): int
    {
        $results ??= $this->sites->staffDebtsPendingForAllSites($actor);
        $counts = Cache::get(self::COUNTS_KEY, []);
        $toDisburse = Cache::get(self::DISBURSE_KEY, []);
        $notified = 0;

        foreach ($results as $result) {
            $code = (string) ($result['site']['code'] ?? '');
            $name = (string) ($result['site']['name'] ?? $code);

            if ($code === '' || ! ($result['ok'] ?? false) || ! is_array($result['data']['pending'] ?? null)) {
                continue;
            }

            $pending = collect($result['data']['pending'])->filter(fn ($row) => is_array($row) && is_string($row['uuid'] ?? null))->values();
            $counts[$code] = $pending->count();
            // ADR-229 — les dettes accordées que le portail doit encore verser.
            $toDisburse[$code] = (int) ($result['meta']['summary']['to_disburse'] ?? 0);

            $this->resolve($code, $pending->pluck('uuid')->all());

            $new = $pending->filter(fn (array $row) => StaffDebtNotice::query()->insertOrIgnore([
                'site_code' => $code,
                'debt_uuid' => $row['uuid'],
                'noticed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0)->values();

            $recipients = self::recipients();
            foreach ($new as $row) {
                if ($recipients->isEmpty()) {
                    break;
                }

                Notification::send($recipients, new StaffDebtRequested(
                    $code,
                    $name,
                    (string) $row['uuid'],
                    (string) ($row['number'] ?? ''),
                    (string) ($row['employee_name'] ?? ''),
                    (string) ($row['amount'] ?? '0'),
                ));
                $notified++;
            }
        }

        Cache::put(self::COUNTS_KEY, $counts, now()->addDay());
        Cache::put(self::DISBURSE_KEY, $toDisburse, now()->addDay());

        return $notified;
    }

    /**
     * Une demande qui n'attend plus (accordée, refusée, retirée) n'est plus à décider :
     * sa notification se marque « Traité », et lue.
     *
     * @param  list<string>  $pendingUuids
     */
    public function resolve(string $siteCode, array $pendingUuids): int
    {
        $pending = array_flip($pendingUuids);
        $changed = 0;

        UserNotification::query()
            ->where('type', StaffDebtRequested::class)
            ->where('created_at', '>=', now()->subDays(90))
            ->get()
            ->each(function (UserNotification $notification) use ($siteCode, $pending, &$changed): void {
                $data = $notification->data ?? [];
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

                if (($meta['site_code'] ?? null) !== $siteCode || isset($meta['resolved_at']) || isset($pending[$meta['debt_uuid'] ?? ''])) {
                    return;
                }

                $meta['resolved_at'] = now()->toIso8601String();
                $data['meta'] = $meta;
                $notification->data = $data;
                $notification->read_at ??= now();
                $notification->save();
                $changed++;
            });

        return $changed;
    }

    /** @return array<string, int> Les demandes à décider par site, à la dernière lecture. */
    public static function pendingCounts(): array
    {
        $counts = Cache::get(self::COUNTS_KEY, []);

        return is_array($counts) ? array_map('intval', $counts) : [];
    }

    /** @return array<string, int> ADR-229 — les dettes accordées à verser par site, à la dernière lecture. */
    public static function toDisburseCounts(): array
    {
        $counts = Cache::get(self::DISBURSE_KEY, []);

        return is_array($counts) ? array_map('intval', $counts) : [];
    }

    /** @return Collection<int, User> Les comptes du portail qui décident (le DG). */
    public static function recipients(): Collection
    {
        return User::query()->where('active', true)->get()->filter(fn (User $user) => $user->can(self::PERMISSION))->values();
    }
}
