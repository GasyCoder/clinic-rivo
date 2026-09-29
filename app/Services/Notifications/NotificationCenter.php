<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\LabResultReturned;
use App\Notifications\LabResultsAddressed;
use App\Notifications\NewEmployeesAwaitingAccess;
use App\Notifications\StaffAccessActivated;
use App\Notifications\StaffAccessReady;
use App\Notifications\WelcomeToPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADR-197 — la boîte de notifications d'un compte : ce qu'il n'a pas lu, tout ce
 * qui est courant, et ce qu'il a archivé. Chacun ne voit que les siennes.
 *
 * Lire une notification ne fait rien d'autre que la marquer lue ; l'archiver la
 * range sans l'effacer. Une notification n'est jamais supprimée par l'écran.
 */
final class NotificationCenter
{
    public const STATUSES = ['unread', 'all', 'archived'];

    public const ACTIONS = ['read', 'unread', 'archive', 'unarchive'];

    /** Les catégories filtrables, et les notifications qui les composent. */
    public const CATEGORIES = [
        'staff_access' => [
            'label' => 'Accès du personnel',
            'types' => [NewEmployeesAwaitingAccess::class, StaffAccessReady::class, StaffAccessActivated::class],
        ],
        // ADR-202 — la bienvenue à la première connexion.
        'account' => [
            'label' => 'Mon compte',
            'types' => [WelcomeToPlatform::class],
        ],
        // ADR-216 — les résultats d'analyses envoyés par le laboratoire.
        'laboratory' => [
            'label' => 'Résultats d’analyses',
            'types' => [LabResultsAddressed::class, LabResultReturned::class],
        ],
    ];

    /** Au-delà, la recherche porterait sur un historique que personne ne relit. */
    private const SEARCH_WINDOW = 1000;

    public const NOT_INSTALLED_MESSAGE = 'Les notifications ne sont pas encore installées sur cette base : '
        .'les migrations doivent d’abord être jouées (php artisan migrate).';

    private ?bool $installed = null;

    /**
     * Une base dont les migrations n'ont pas été jouées n'a pas la table : la page le
     * dit au lieu d'une erreur SQL, comme les Paramètres (ADR-184).
     */
    public function installed(): bool
    {
        if ($this->installed === null) {
            try {
                $this->installed = Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'archived_at');
            } catch (Throwable) {
                $this->installed = false;
            }
        }

        return $this->installed;
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()->for($user)->whereNull('archived_at')->whereNull('read_at')->count();
    }

    /** @return array{unread: int, all: int, archived: int} */
    public function counts(User $user): array
    {
        $base = fn () => UserNotification::query()->for($user);

        return [
            'unread' => $base()->whereNull('archived_at')->whereNull('read_at')->count(),
            'all' => $base()->whereNull('archived_at')->count(),
            'archived' => $base()->whereNotNull('archived_at')->count(),
        ];
    }

    /** @return list<array<string, mixed>> Les plus récentes, pour la cloche. */
    public function latest(User $user, int $limit = 8): array
    {
        return UserNotification::query()->for($user)
            ->whereNull('archived_at')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (UserNotification $notification) => $this->present($notification))
            ->all();
    }

    /** @return LengthAwarePaginator<array<string, mixed>> */
    public function page(User $user, string $status, ?string $category, string $search, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->filtered($user, $status, $category);
        $search = trim($search);

        if ($search === '') {
            $paginator = $query->latest()->paginate($perPage, ['*'], 'page', $page);
            $paginator->setCollection($paginator->getCollection()->map(fn (UserNotification $notification) => $this->present($notification)));

            return $paginator;
        }

        // Le texte est rangé en JSON (accents échappés) : la recherche se fait ici, sur
        // les notifications récentes du compte, sans accents ni majuscules, tous les mots.
        $words = preg_split('/\s+/', self::fold($search)) ?: [];
        $matches = $query->latest()->limit(self::SEARCH_WINDOW)->get()
            ->filter(function (UserNotification $notification) use ($words): bool {
                $haystack = self::fold(implode(' ', [$notification->data['title'] ?? '', $notification->data['body'] ?? '', self::CATEGORIES[$notification->data['category'] ?? '']['label'] ?? '']));

                return collect($words)->every(fn (string $word) => str_contains($haystack, $word));
            })
            ->values();

        return new LengthAwarePaginator(
            $matches->forPage($page, $perPage)->map(fn (UserNotification $notification) => $this->present($notification))->values(),
            $matches->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * Marque lues, non lues, archivées ou désarchivées les notifications du compte
     * désignées. Celles d'un autre compte sont ignorées, jamais touchées.
     *
     * @param  list<string>  $ids
     */
    public function apply(User $user, array $ids, string $action): int
    {
        $query = UserNotification::query()->for($user)->whereIn('id', $ids);

        return match ($action) {
            'read' => $query->whereNull('read_at')->update(['read_at' => now()]),
            'unread' => $query->whereNotNull('read_at')->update(['read_at' => null]),
            // Archiver, c'est aussi l'avoir vue.
            'archive' => $query->whereNull('archived_at')->update(['archived_at' => now(), 'read_at' => now()]),
            'unarchive' => $query->whereNotNull('archived_at')->update(['archived_at' => null]),
            default => 0,
        };
    }

    public function readAll(User $user): int
    {
        return UserNotification::query()->for($user)->whereNull('archived_at')->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function find(User $user, string $id): UserNotification
    {
        return UserNotification::query()->for($user)->whereKey($id)->firstOrFail();
    }

    /** @return list<array{key: string, label: string}> */
    public function categories(): array
    {
        return collect(self::CATEGORIES)->map(fn (array $category, string $key) => ['key' => $key, 'label' => $category['label']])->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(UserNotification $notification): array
    {
        $data = $notification->data ?? [];
        $category = (string) ($data['category'] ?? 'general');

        return [
            'id' => $notification->id,
            'kind' => (string) ($data['kind'] ?? ''),
            'category' => $category,
            'category_label' => self::CATEGORIES[$category]['label'] ?? 'Général',
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => (string) ($data['body'] ?? ''),
            // Ouvrir passe par RIVO : la notification est marquée lue, puis on suit son lien.
            'open_url' => '/notifications/'.$notification->id.'/ouvrir',
            'has_target' => self::safeUrl($data['url'] ?? null) !== null,
            'icon' => (string) ($data['icon'] ?? 'bell'),
            'tone' => (string) ($data['tone'] ?? 'primary'),
            'read' => $notification->read_at !== null,
            'archived' => $notification->archived_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            // ADR-199 — ce que la notification annonçait a été fait (ou l'est en partie).
            'resolution' => self::resolution($data['meta'] ?? null),
        ];
    }

    /**
     * « Traité » quand ce qu'elle annonçait n'attend plus rien ; sinon combien
     * attendent encore, si ce n'est plus tout ; `null` quand rien n'a changé.
     *
     * @return array{state: string, label: string}|null
     */
    private static function resolution(mixed $meta): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        if (isset($meta['resolved_at'])) {
            return ['state' => 'done', 'label' => 'Traité — tous ont leur accès'];
        }

        $count = (int) ($meta['count'] ?? 0);
        $waiting = isset($meta['waiting']) ? (int) $meta['waiting'] : null;

        return $waiting !== null && $count > 0 && $waiting < $count
            ? ['state' => 'partial', 'label' => $waiting.' sur '.$count.' attend'.($waiting > 1 ? 'ent' : '').' encore']
            : null;
    }

    /** Un lien interne seulement : jamais une adresse extérieure glissée dans une notification. */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return $url;
    }

    /** @return Builder<UserNotification> */
    private function filtered(User $user, string $status, ?string $category): Builder
    {
        $query = UserNotification::query()->for($user);

        match ($status) {
            'unread' => $query->whereNull('archived_at')->whereNull('read_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };

        if ($category !== null && isset(self::CATEGORIES[$category])) {
            $query->whereIn('type', self::CATEGORIES[$category]['types']);
        }

        return $query;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
