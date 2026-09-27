<?php

namespace App\Http\Controllers\Administration;

use App\Actions\StaffAccess\ReopenStaffAccessActivationAction;
use App\Http\Controllers\Controller;
use App\Models\StaffAccessHandover;
use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Services\Auth\AccountActivation;
use App\Services\StaffAccess\StaffAccessDirectory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-197 / ADR-202 — le RH reçoit les accès créés par le Super Admin et les
 * annonce aux employés : « votre compte est créé, connectez-vous sur ce lien avec
 * votre adresse ». Chaque employé choisit lui-même son mot de passe à sa première
 * connexion ; le RH voit qui s'est connecté et rouvre le délai d'un retardataire.
 * Seules les remises envoyées lui sont visibles.
 */
class StaffAccessController extends Controller
{
    public function __construct(private readonly StaffAccessDirectory $directory) {}

    /** Les vues de la liste, exclusives : ce qui demande un geste du RH d'abord. */
    private const VIEWS = ['toutes', 'a-rouvrir', 'en-attente', 'terminees'];

    public function index(Request $request): Response
    {
        $view = in_array($request->query('vue'), self::VIEWS, true) ? (string) $request->query('vue') : 'toutes';
        $search = trim(mb_substr((string) $request->query('q', ''), 0, 100));

        // Les comptes des cartes : chacun est ce que donnerait un clic, la recherche comprise.
        $base = fn () => $this->searched(StaffAccessHandover::query()->sent(), $search);
        $counts = collect(self::VIEWS)->mapWithKeys(fn (string $name) => [$name => $this->inView($base(), $name)->count()])->all();

        $handovers = $this->inView($base(), $view)
            ->with('items.user:'.StaffAccessDirectory::USER_COLUMNS)
            // Ce qui demande un geste d'abord (délai dépassé), puis ce qui attend, puis le reste.
            ->withExists(['items as needs_reopen' => fn (Builder $items) => $items->whereHas('user', fn (Builder $user) => $user->activationExpired())])
            ->withExists(['items as is_waiting' => fn (Builder $items) => $items->whereHas('user', fn (Builder $user) => $user->awaitingActivation())])
            ->orderByDesc('needs_reopen')
            ->orderByDesc('is_waiting')
            ->latest('sent_at')
            ->paginate(10)
            ->withQueryString();
        $handovers->setCollection($handovers->getCollection()->map(fn (StaffAccessHandover $handover) => $this->directory->handover($handover)));

        $sentUsers = fn () => User::query()->whereIn('id', StaffAccessHandoverItem::query()
            ->whereNotNull('user_id')
            ->whereHas('handover', fn (Builder $handover) => $handover->whereNotNull('sent_at'))
            ->select('user_id'));
        $next = $sentUsers()->awaitingActivation()->min('activation_open_until');

        return Inertia::render('Administration/StaffAccess/Index', [
            'handovers' => $handovers,
            'counts' => $counts,
            // Le délai le plus proche parmi les employés qui ne se sont pas encore connectés.
            'nextDeadline' => $next !== null ? Carbon::parse($next)->toIso8601String() : null,
            'people' => [
                'waiting' => $sentUsers()->awaitingActivation()->count(),
                'expired' => $sentUsers()->activationExpired()->count(),
            ],
            'filters' => ['vue' => $view, 'q' => $search],
            'loginUrl' => route('login'),
            'activationDays' => AccountActivation::days(),
        ]);
    }

    /**
     * @param  Builder<StaffAccessHandover>  $query
     * @return Builder<StaffAccessHandover>
     */
    private function inView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'a-rouvrir' => $query->toReopen(),
            'en-attente' => $query->waiting()->whereNot(fn (Builder $not) => $not->toReopen()),
            'terminees' => $query->whereNot(fn (Builder $not) => $not->toReopen())->whereNot(fn (Builder $not) => $not->waiting()),
            default => $query,
        };
    }

    /**
     * Une remise qui contient l'employé cherché : nom, matricule, identifiant ou adresse.
     *
     * @param  Builder<StaffAccessHandover>  $query
     * @return Builder<StaffAccessHandover>
     */
    private function searched(Builder $query, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return $query->whereHas('items', fn (Builder $items) => $items->where(fn (Builder $where) => $where
            ->where('employee_name', 'like', $like)
            ->orWhere('employee_number', 'like', $like)
            ->orWhere('login_email', 'like', $like)
            ->orWhere('mailbox_address', 'like', $like)));
    }

    public function show(Request $request, StaffAccessHandover $handover): Response
    {
        abort_if($handover->sent_at === null, 404);

        return Inertia::render('Administration/StaffAccess/Show', [
            'handover' => $this->directory->handover($handover->load('items.user:'.StaffAccessDirectory::USER_COLUMNS)),
            'siteName' => config('rivo.site.name'),
            'brand' => config('app.name'),
            'loginUrl' => route('login'),
            'activationDays' => AccountActivation::days(),
        ]);
    }

    public function reopen(Request $request, StaffAccessHandover $handover, StaffAccessHandoverItem $item, ReopenStaffAccessActivationAction $action): RedirectResponse
    {
        abort_if($handover->sent_at === null || $item->staff_access_handover_id !== $handover->getKey(), 404);

        $user = $action->execute($item, $request->user());

        return back()->with('status', 'Première connexion de '.$item->employee_name.' rouverte jusqu’au '.$user->activation_open_until->format('d/m/Y').'.');
    }
}
