<?php

namespace App\Services\Episode;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\ReceptionNextStep;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeReceptionNextStep;
use App\Models\User;
use App\Support\CareRequestSummary;
use App\Support\EpisodeEntryPath;
use App\Support\EpisodeQueuePresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * ADR-177 — les passages ouverts, tels qu'un service clinique les voit.
 *
 * Quatre notions que l'ancienne file confondait sont ici séparées :
 *
 * ```text
 * BESOIN        pourquoi le patient vient — les prestations déclarées à l'arrivée
 * SUGGESTION    la prochaine étape que la Réception propose — facultative, indicative
 * VISIBILITÉ    tout passage ouvert et accueilli, pour tout service autorisé
 * PRISE EN      une vraie orientation vers ce service, prise en charge ou à prendre
 * CHARGE
 * ```
 *
 * La visibilité ne dépend **jamais** de la suggestion : un passage suggéré
 * « Soins » est vu par Médecine, un passage sans suggestion est vu par tous.
 * Elle dépend de l'état du passage — ouvert, accueil terminé à la Réception
 * (ou urgence), parcours clinique pas encore clos — et du droit d'ouvrir le
 * tableau, que la route vérifie. La prise en charge reste une action métier
 * distincte (`TakeChargeOfEpisodeAction`) ; rien n'est créé en regardant.
 *
 * Voir un passage n'est pas lire son dossier : une ligne ne porte que de quoi
 * s'organiser — identité, numéro, heure, priorité, besoin, suggestion, état du
 * service. Aucune donnée clinique n'y figure ; le dossier reste gardé par ses
 * propres permissions.
 *
 * Trois blocs qui ne se chevauchent pas — un passage n'est que dans l'un
 * d'eux, et la somme de leurs comptes est le nombre de passages vus ici —,
 * plus deux filtres, les mêmes pour Soins, Médecine et Maternité :
 *
 * ```text
 * waiting      En attente : arrivés, pas encore pris en charge par ce service —
 *              chacun avec son n° de file, par ordre d'arrivée (vue par défaut)
 * in_progress  En cours chez moi : pris en charge par ce service
 * completed    Terminés chez moi : prise en charge terminée, passage encore ouvert
 *
 * suggested    filtre du bloc « En attente » : la Réception a suggéré ce service
 * emergency    filtre : passages actifs en urgence, quel que soit leur état ici
 * ```
 *
 * Un passage en attente l'est qu'une vraie orientation l'ait envoyé ici
 * (« orienté · à prendre ») ou non (« pas encore pris en charge ») : les deux
 * attendent, ils partagent donc la même file et la même numérotation.
 *
 * Par où il devrait entrer se lit sur la ligne (`pathway`, `EpisodeEntryPath`) :
 * la Médecine est prévenue qu'un patient est attendu aux Soins d'abord et
 * décide ; les Soins ne prennent pas un patient attendu directement en Médecine
 * — ce passage reste visible, mais ne tient pas de place dans leur file.
 */
final class ActiveEpisodeBoard
{
    public const WAITING = 'waiting';

    public const SUGGESTED = 'suggested';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const EMERGENCY = 'emergency';

    public const VIEWS = [self::WAITING, self::SUGGESTED, self::IN_PROGRESS, self::COMPLETED, self::EMERGENCY];

    public const STATE_NONE = 'NONE';

    public const STATE_REQUESTED = 'REQUESTED';

    public const STATE_IN_PROGRESS = 'IN_PROGRESS';

    public const STATE_COMPLETED = 'COMPLETED';

    /**
     * Épingle en tête une urgence que la Médecine n'a pas encore vue — la règle
     * des anciennes files d'orientations (ADR-124), lue ici sur le passage.
     */
    private const PIN_UNSEEN_EMERGENCY_SQL = <<<'SQL'
        CASE WHEN episodes.priority = 'EMERGENCY' AND NOT EXISTS (
            SELECT 1 FROM episode_orientations eo_seen
            WHERE eo_seen.episode_id = episodes.id
            AND eo_seen.destination_module = 'MEDICINE'
            AND eo_seen.status = 'COMPLETED'
        ) THEN 0 ELSE 1 END
        SQL;

    /**
     * Ce que chaque espace permet depuis le tableau, et avec quels droits.
     * Les adresses sont produites ici : l'écran ne reconstruit aucune règle.
     *
     * @var array<string, array{take_route: string, take_permissions: list<string>, open_route: string, open_permission: string, release_route: ?string, release_permission: ?string}>
     */
    private const WORKSPACES = [
        'CARE' => [
            'take_route' => 'care.passages.take-charge',
            'take_permissions' => ['care.create', 'care.update'],
            'open_route' => 'care.orientations.show',
            'open_permission' => 'care.view',
            'release_route' => 'care.orientations.release',
            'release_permission' => 'care.update',
        ],
        'MEDICINE' => [
            'take_route' => 'medicine.passages.take-charge',
            'take_permissions' => ['consultations.create'],
            'open_route' => 'medicine.orientations.step',
            'open_permission' => 'consultations.view',
            'release_route' => 'medicine.orientations.release',
            'release_permission' => 'consultations.create',
        ],
        'MATERNITY' => [
            'take_route' => 'maternity.passages.take-charge',
            'take_permissions' => ['maternity.update'],
            'open_route' => 'maternity.orientations.show',
            'open_permission' => 'maternity.view',
            'release_route' => null,
            'release_permission' => null,
        ],
    ];

    public function __construct(private readonly EpisodeQueuePresenter $queues) {}

    public static function supports(CatalogModule $module): bool
    {
        return array_key_exists($module->value, self::WORKSPACES);
    }

    /** Une vue inconnue retombe sur le travail à faire — « En attente » —, jamais sur une liste vide. */
    public function normalizeView(mixed $view): string
    {
        return in_array($view, self::VIEWS, true) ? $view : self::WAITING;
    }

    /**
     * Combien de passages dans chaque vue. Le compte vient d'ici, jamais de la
     * page affichée : recalculé à l'écran, il mentirait dès la deuxième page.
     *
     * @return array<string, int>
     */
    public function counts(CatalogModule $module): array
    {
        $this->ensureSupported($module);

        return collect(self::VIEWS)
            ->mapWithKeys(fn (string $view): array => [$view => $this->scope($this->base(), $module, $view)->count()])
            ->all();
    }

    /**
     * Une page du tableau, lignes déjà présentées.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(CatalogModule $module, string $view, string $search, User $user, int $perPage = 20): LengthAwarePaginator
    {
        $this->ensureSupported($module);
        $view = $this->normalizeView($view);

        $paginator = $this->order($this->search($this->scope($this->base(), $module, $view), $search), $module, $view)
            ->with([
                'patient',
                'serviceRequests',
                'receptionNextSteps',
                'orientations' => fn ($query) => $query->orderBy('oriented_at')->orderBy('id'),
                'orientations.acceptedBy:id,name',
                ...($module === CatalogModule::Medicine ? ['orientations.consultation:id,episode_orientation_id'] : []),
            ])
            ->paginate($perPage)
            ->withQueryString();

        $episodes = $paginator->getCollection();
        $current = $episodes
            ->map(fn (Episode $episode) => $this->currentOrientation($episode, $module))
            ->filter()
            ->values();
        $queueNumbers = $this->queueNumbers($module);
        $context = [
            'queue_numbers' => $queueNumbers,
            'medicine_numbers' => $module === CatalogModule::Medicine
                ? $queueNumbers
                : $this->queueNumbers(CatalogModule::Medicine),
            'pending_reasons' => $module === CatalogModule::Medicine
                ? $this->queues->pendingReasonsFor($current
                    ->filter(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::InProgress)
                    ->values())
                : [],
            // ADR-118 — ce qu'une orientation Soins demandée par le médecin
            // contient : demandeur, suite décidée, actes (ceux-ci avec
            // `care_orders.view`). Une orientation sans demande n'en invente pas.
            'care_requests' => $module === CatalogModule::Care
                ? CareRequestSummary::forOrientations($current->map->getKey(), $user->can('care_orders.view'))
                : [],
        ];

        $paginator->through(fn (Episode $episode): array => $this->present($episode, $module, $user, $context));

        return $paginator;
    }

    /**
     * Le n° de file de chaque passage en attente pour ce service, sur **toute**
     * la file — jamais sur la page ou le filtre affichés : un patient garde le
     * même numéro d'un écran à l'autre, et celui que les Soins lisent pour la
     * Médecine est celui du médecin.
     *
     * Par ordre d'arrivée à la clinique. Une urgence que la Médecine n'a pas
     * encore vue est épinglée en tête, sans numéro : elle ne prend la place de
     * personne (ADR-021, ADR-124).
     *
     * @return array<int, int> n° de file indexé par Episode::id
     */
    public function queueNumbers(CatalogModule $module): array
    {
        $this->ensureSupported($module);

        $rows = $this->scope($this->base(), $module, self::WAITING)
            ->select('episodes.id')
            ->selectRaw(self::PIN_UNSEEN_EMERGENCY_SQL.' as queue_pin')
            ->orderBy('episodes.started_at')
            ->orderBy('episodes.id')
            ->toBase()
            ->get();

        $elsewhere = $this->expectedElsewhere($module, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $numbers = [];
        $rank = 0;

        foreach ($rows as $row) {
            if ((int) $row->queue_pin === 1 && ! isset($elsewhere[(int) $row->id])) {
                $numbers[(int) $row->id] = ++$rank;
            }
        }

        return $numbers;
    }

    /**
     * Les passages que ce service ne peut pas prendre parce qu'ils sont attendus
     * ailleurs (`EpisodeEntryPath`, refus des Soins) : ils restent visibles mais
     * ne tiennent aucune place dans sa file. Seuls les Soins refusent ; les
     * relations sont lues en trois requêtes pour toute la file.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function expectedElsewhere(CatalogModule $module, array $ids): array
    {
        if ($module !== CatalogModule::Care || $ids === []) {
            return [];
        }

        return Episode::query()
            ->whereIn('id', $ids)
            ->with(['serviceRequests', 'receptionNextSteps', 'orientations'])
            ->get()
            ->filter(fn (Episode $episode) => EpisodeEntryPath::guard($episode, $module)['blocking'] ?? false)
            ->mapWithKeys(fn (Episode $episode): array => [$episode->getKey() => true])
            ->all();
    }

    /**
     * L'état du passage chez ce service — la seule question que le tableau
     * pose à `EpisodeOrientation`. Une orientation annulée ne compte pas.
     */
    public function stateOf(Episode $episode, CatalogModule $module): string
    {
        return $this->stateFor($this->currentOrientation($episode, $module));
    }

    /** Les passages ouverts dont le dossier patient existe. */
    private function base(): Builder
    {
        return Episode::query()
            ->where('episodes.status', EpisodeStatus::Open->value)
            // Un patient n'est jamais réellement supprimé (ADR-010) : cette
            // garde ne protège que d'une corruption qui contournerait Eloquent.
            ->whereHas('patient');
    }

    /**
     * Un passage actif, du point de vue clinique :
     *
     * - l'accueil est terminé à la Réception — ou c'est une urgence, ou une
     *   vraie orientation existe déjà : un passage encore en cours de saisie à
     *   l'accueil n'est pas encore à prendre ;
     * - son parcours clinique n'est pas clos : un passage en attente de
     *   règlement n'attend plus que la Réception (ADR-090), sauf si une vraie
     *   orientation y est encore active.
     *
     * Aucune de ces conditions ne lit la suggestion de la Réception.
     */
    private function active(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where('episodes.administrative_status', '!=', EpisodeAdministrativeStatus::PendingSettlement->value)
                    ->orWhereHas('orientations', fn (Builder $orientation) => $orientation
                        ->whereIn('status', $this->values([EpisodeOrientationStatus::Pending, EpisodeOrientationStatus::InProgress])));
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('episodes.service_plan_finalized_at')
                    ->orWhere('episodes.priority', EpisodePriority::Emergency->value)
                    ->orWhereHas('orientations', fn (Builder $orientation) => $orientation
                        ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value));
            });
    }

    private function scope(Builder $query, CatalogModule $module, string $view): Builder
    {
        return match ($this->normalizeView($view)) {
            self::SUGGESTED => $this->waiting($query, $module)
                ->whereHas('receptionNextSteps', fn (Builder $step) => $step->where('module', $module->value)),
            self::IN_PROGRESS => $query
                ->whereHas('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::InProgress])),
            self::COMPLETED => $query
                ->whereHas('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::Completed]))
                ->whereDoesntHave('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::Pending, EpisodeOrientationStatus::InProgress])),
            self::EMERGENCY => $this->active($query)->where('episodes.priority', EpisodePriority::Emergency->value),
            default => $this->waiting($query, $module),
        };
    }

    /**
     * En attente pour ce service : un passage actif que ce service n'a pas pris
     * en charge — une vraie orientation l'attend, ou il ne l'a encore jamais
     * pris. Un passage que ce service a déjà terminé n'y revient que par une
     * nouvelle vraie orientation.
     */
    private function waiting(Builder $query, CatalogModule $module): Builder
    {
        return $this->active($query)
            ->whereDoesntHave('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::InProgress]))
            ->where(fn (Builder $query) => $query
                ->whereHas('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::Pending]))
                ->orWhereDoesntHave('orientations', fn (Builder $orientation) => $this->toward($orientation, $module, [EpisodeOrientationStatus::Completed])));
    }

    /** @param list<EpisodeOrientationStatus> $statuses */
    private function toward(Builder $orientation, CatalogModule $module, array $statuses): Builder
    {
        return $orientation
            ->where('destination_module', $module->value)
            ->whereIn('status', $this->values($statuses));
    }

    private function search(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('episodes.episode_number', 'like', "%{$search}%")
            ->orWhereHas('patient', fn (Builder $patient) => $patient
                ->where('patient_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")));
    }

    /**
     * La file se lit du premier arrivé au dernier, une urgence pas encore vue en
     * tête — l'ordre même de `queueNumbers()`, pour que le n° se lise de haut en
     * bas. Ce qui est en cours se lit par ancienneté de la prise en charge ; ce
     * qui est terminé, du plus récent au plus ancien.
     */
    private function order(Builder $query, CatalogModule $module, string $view): Builder
    {
        if ($view === self::IN_PROGRESS) {
            return $query
                ->orderByRaw(
                    '(SELECT MIN(eo_take.accepted_at) FROM episode_orientations eo_take WHERE eo_take.episode_id = episodes.id AND eo_take.destination_module = ? AND eo_take.status = ?)',
                    [$module->value, EpisodeOrientationStatus::InProgress->value],
                )
                ->orderBy('episodes.id');
        }

        if ($view === self::COMPLETED) {
            return $query
                ->orderByRaw(
                    '(SELECT MAX(eo_done.completed_at) FROM episode_orientations eo_done WHERE eo_done.episode_id = episodes.id AND eo_done.destination_module = ? AND eo_done.status = ?) DESC',
                    [$module->value, EpisodeOrientationStatus::Completed->value],
                )
                ->orderByDesc('episodes.id');
        }

        return $query
            ->orderByRaw(self::PIN_UNSEEN_EMERGENCY_SQL)
            ->orderBy('episodes.started_at')
            ->orderBy('episodes.id');
    }

    /**
     * La ligne d'un passage : de quoi s'organiser, rien de clinique.
     *
     * `$context` porte ce qui se calcule une fois pour toute la page : les n°
     * d'ordre des vraies orientations en attente (ce service et Médecine), les
     * résultats attendus d'une consultation en cours, les demandes de soins du
     * médecin.
     *
     * @param  array{queue_numbers: array<int, int>, medicine_numbers: array<int, int>, pending_reasons: array<int, array<int, string>>, care_requests: array<int, array<string, mixed>>}  $context
     * @return array<string, mixed>
     */
    private function present(Episode $episode, CatalogModule $module, User $user, array $context): array
    {
        $orientation = $this->currentOrientation($episode, $module);
        $state = $this->stateFor($orientation);
        $reasons = $orientation ? ($context['pending_reasons'][$orientation->getKey()] ?? []) : [];
        $queueNumbers = $context['queue_numbers'];
        $medicineNumbers = $context['medicine_numbers'];
        $patient = $episode->patient;
        $nextSteps = $this->nextStepsOf($episode);
        // Le parcours prévu ne dépend pas du compte : un patient attendu en
        // Médecine ne tient de place dans la file des Soins pour personne.
        $guard = $state === self::STATE_NONE ? EpisodeEntryPath::guard($episode, $module) : null;
        $blocked = $guard['blocking'] ?? false;

        return [
            'uuid' => $episode->uuid,
            // Pour le garde-fou « un patient attend avant celui-ci » (ADR-121) :
            // tout passage en attente ici est une place dans la file — sauf
            // celui que ce service ne peut pas prendre, attendu ailleurs.
            'status' => $this->isWaitingState($state) && ! $blocked ? EpisodeOrientationStatus::Pending->value : $state,
            'oriented_at' => $orientation?->oriented_at ?? $episode->started_at,
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'started_at' => $episode->started_at,
                'administrative_status' => $episode->administrative_status->value,
                'administrative_status_label' => $episode->administrative_status->label(),
                'patient' => [
                    'uuid' => $patient->uuid,
                    'patient_number' => $patient->patient_number,
                    'first_name' => $patient->first_name,
                    'last_name' => $patient->last_name,
                    'sex' => $patient->sex?->value,
                    'age' => $patient->birth_date?->age ?? $patient->declared_age,
                ],
            ],
            // Le besoin déclaré, sans aucun montant : un tableau de passages
            // n'est pas une caisse (ADR-036).
            'needs' => $episode->serviceRequests
                ->map(fn ($request): array => [
                    'description' => $request->designation,
                    'quantity' => $request->quantity,
                    'module' => $request->module->value,
                    'module_label' => $request->module->label(),
                ])
                ->values()
                ->all(),
            'need_to_specify' => $episode->designation_deferred || $episode->serviceRequests->isEmpty(),
            'next_steps' => $nextSteps,
            'suggested_for_me' => in_array($module->value, array_column($nextSteps, 'value'), true),
            'module' => [
                'key' => $module->value,
                'label' => $module->label(),
                'state' => $state,
                'state_label' => $this->stateLabel($state, $reasons),
                'orientation_uuid' => $orientation?->uuid,
                'source_label' => $orientation?->source_module->label(),
                'reason' => $state === self::STATE_REQUESTED ? $orientation?->reason : null,
                'oriented_at' => $orientation?->oriented_at,
                'accepted_at' => $orientation?->accepted_at,
                'completed_at' => $orientation?->completed_at,
                'accepted_by' => $orientation?->acceptedBy?->name,
                'accepted_by_id' => $orientation?->accepted_by,
                'is_mine' => $orientation !== null && $orientation->accepted_by === $user->getKey(),
                'queue_number' => $this->isWaitingState($state) && ! $blocked ? ($queueNumbers[$episode->getKey()] ?? null) : null,
                'is_waiting' => $this->isWaitingState($state),
                'pending_reasons' => $reasons,
                'is_waiting_on_results' => $reasons !== [],
            ],
            'care_request' => $orientation ? ($context['care_requests'][$orientation->getKey()] ?? null) : null,
            // Où le patient est en ce moment, ailleurs : une information de
            // routage, de même nature que l'orientation elle-même (ADR-117).
            'elsewhere' => $episode->orientations
                ->filter(fn (EpisodeOrientation $other) => $other->destination_module !== $module && $other->status->isActive())
                ->map(fn (EpisodeOrientation $other): array => [
                    'module' => $other->destination_module->value,
                    'label' => $other->destination_module->label(),
                    'state' => $other->status->value,
                    'state_label' => $other->status === EpisodeOrientationStatus::InProgress ? 'pris en charge' : 'orienté, en attente',
                    'by' => $other->status === EpisodeOrientationStatus::InProgress ? $other->acceptedBy?->name : null,
                    'queue_number' => $other->destination_module === CatalogModule::Medicine && $other->status === EpisodeOrientationStatus::Pending
                        ? ($medicineNumbers[$episode->getKey()] ?? null)
                        : null,
                ])
                ->values()
                ->all(),
            // Par où ce patient devrait entrer, quand ce service s'apprête à le
            // prendre à contre-sens : servi seulement à qui peut le prendre.
            'pathway' => $this->pathway($guard, $episode, $module, $user),
            'actions' => $this->actions($episode, $module, $user, $orientation, $state, $blocked),
        ];
    }

    /**
     * Ce que la ligne dit du parcours prévu (`EpisodeEntryPath`) — servi
     * seulement à qui pourrait prendre le patient ici : c'est à lui que le
     * rappel s'adresse. Devant un patient attendu aux Soins, la Médecine reçoit
     * l'adresse pour les faire elle-même, si ses droits Soins le permettent.
     *
     * @param  array{code: string, blocking: bool, title: string, message: string, reasons: list<string>}|null  $guard
     * @return array{code: string, blocking: bool, title: string, message: string, reasons: list<string>, care_take_charge_url: ?string}|null
     */
    private function pathway(?array $guard, Episode $episode, CatalogModule $module, User $user): ?array
    {
        if ($guard === null || ! $this->canTake($module, $user)) {
            return null;
        }

        return [
            ...$guard,
            'care_take_charge_url' => $guard['code'] === EpisodeEntryPath::CARE_FIRST
                && $this->canTake(CatalogModule::Care, $user)
                && $user->can(self::WORKSPACES['CARE']['open_permission'])
                    ? route(self::WORKSPACES['CARE']['take_route'], $episode)
                    : null,
        ];
    }

    private function canTake(CatalogModule $module, User $user): bool
    {
        return collect(self::WORKSPACES[$module->value]['take_permissions'])
            ->every(fn (string $permission) => $user->can($permission));
    }

    /**
     * Les gestes que ce compte peut faire depuis la ligne. Une adresse absente
     * dit « pas ce geste ici » — l'écran n'a rien à décider.
     *
     * Le journal de traitement et le dossier médical du passage accompagnent ce
     * qui est terminé ici : c'est là qu'on relit ce qui a été fait. Chacun n'est
     * proposé qu'avec le droit que sa route exige.
     *
     * @return array{take_charge_url: ?string, open_url: ?string, release_url: ?string, passage_url: ?string, journal_url: ?string, medical_record_url: ?string}
     */
    private function actions(Episode $episode, CatalogModule $module, User $user, ?EpisodeOrientation $orientation, string $state, bool $blocked): array
    {
        $config = self::WORKSPACES[$module->value];
        // Attendu ailleurs : aucune adresse de prise en charge — le serveur la
        // refuserait de toute façon (`TakeChargeOfEpisodeAction`).
        $canTake = ! $blocked && $this->canTake($module, $user);

        $openUrl = null;

        if ($orientation !== null
            && in_array($state, [self::STATE_IN_PROGRESS, self::STATE_COMPLETED], true)
            && $user->can($config['open_permission'])) {
            $openUrl = $module === CatalogModule::Medicine
                ? route($config['open_route'], [$orientation, 'dossier'])
                : route($config['open_route'], $orientation);
        }

        return [
            'take_charge_url' => $canTake && in_array($state, [self::STATE_NONE, self::STATE_REQUESTED], true)
                ? route($config['take_route'], $episode)
                : null,
            'open_url' => $openUrl,
            'release_url' => $state === self::STATE_IN_PROGRESS
                && $config['release_route'] !== null
                && $orientation?->accepted_by === $user->getKey()
                && $user->can((string) $config['release_permission'])
                    ? route($config['release_route'], $orientation)
                    : null,
            'passage_url' => $user->can('patients.view') ? route('passages.show', $episode) : null,
            'journal_url' => $state === self::STATE_COMPLETED && $user->can('treatment_journal.view')
                ? route('passages.treatment-journal.show', $episode)
                : null,
            'medical_record_url' => $state === self::STATE_COMPLETED && $user->can('patients.view')
                ? route('passages.medical-record.print', $episode)
                : null,
        ];
    }

    /**
     * L'orientation qui dit où en est ce service : l'active s'il y en a une —
     * une seule par service grâce à l'`active_key` —, sinon la dernière terminée.
     */
    private function currentOrientation(Episode $episode, CatalogModule $module): ?EpisodeOrientation
    {
        $mine = $episode->orientations->filter(
            fn (EpisodeOrientation $orientation) => $orientation->destination_module === $module
                && $orientation->status !== EpisodeOrientationStatus::Cancelled,
        );

        return $mine->first(fn (EpisodeOrientation $orientation) => $orientation->status->isActive())
            ?? $mine
                ->filter(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::Completed)
                ->sortBy(fn (EpisodeOrientation $orientation) => $orientation->completed_at?->getTimestamp() ?? 0)
                ->last();
    }

    private function isWaitingState(string $state): bool
    {
        return in_array($state, [self::STATE_NONE, self::STATE_REQUESTED], true);
    }

    private function stateFor(?EpisodeOrientation $orientation): string
    {
        return match ($orientation?->status) {
            EpisodeOrientationStatus::Pending => self::STATE_REQUESTED,
            EpisodeOrientationStatus::InProgress => self::STATE_IN_PROGRESS,
            EpisodeOrientationStatus::Completed => self::STATE_COMPLETED,
            default => self::STATE_NONE,
        };
    }

    /** @param array<int, string> $reasons */
    private function stateLabel(string $state, array $reasons): string
    {
        return match ($state) {
            self::STATE_REQUESTED => 'Orienté · en attente',
            self::STATE_IN_PROGRESS => $reasons !== [] ? 'En attente de résultat' : 'Pris en charge',
            self::STATE_COMPLETED => 'Terminé',
            default => 'En attente',
        };
    }

    /** @return list<array{value: string, label: string}> */
    private function nextStepsOf(Episode $episode): array
    {
        $values = $episode->receptionNextSteps
            ->map(fn (EpisodeReceptionNextStep $step): string => $step->module->value)
            ->all();

        return array_values(array_map(
            fn (string $value): array => ['value' => $value, 'label' => ReceptionNextStep::from($value)->label()],
            array_intersect(ReceptionNextStep::values(), $values),
        ));
    }

    /**
     * @param  list<EpisodeOrientationStatus>  $statuses
     * @return list<string>
     */
    private function values(array $statuses): array
    {
        return array_map(fn (EpisodeOrientationStatus $status): string => $status->value, $statuses);
    }

    private function ensureSupported(CatalogModule $module): void
    {
        if (! self::supports($module)) {
            throw new InvalidArgumentException("Le module {$module->value} ne lit pas le tableau des passages.");
        }
    }
}
