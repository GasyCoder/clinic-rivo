<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationCenter;
use App\Services\StaffAccess\StaffAccessWatcher;
use App\Services\Webmail\WebmailAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-197 — la boîte de notifications du compte connecté : la page complète
 * (non lues, toutes, archivées), le résumé que la cloche relit, et les gestes
 * qui les rangent. Chacun ne touche que les siennes ; aucune permission n'est
 * nécessaire, comme pour « Mon profil ».
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = in_array($request->query('status'), NotificationCenter::STATUSES, true) ? $request->query('status') : 'all';
        $category = is_string($request->query('category')) && isset(NotificationCenter::CATEGORIES[$request->query('category')]) ? $request->query('category') : null;
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        if (! $this->center->installed()) {
            return Inertia::render('Notifications/Index', [
                'inbox' => new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url()]),
                'counts' => ['unread' => 0, 'all' => 0, 'archived' => 0],
                'categories' => $this->center->categories(),
                'filters' => ['status' => $status, 'category' => $category, 'q' => $search],
                'unavailable' => NotificationCenter::NOT_INSTALLED_MESSAGE,
            ]);
        }

        $this->watchPortal($request);

        return Inertia::render('Notifications/Index', [
            // Pas `notifications` : ce nom masquerait la prop partagée qui porte la pastille de la cloche.
            'inbox' => $this->center->page($user, $status, $category, $search, max(1, (int) $request->query('page', 1)))->withQueryString(),
            'counts' => $this->center->counts($user),
            'categories' => $this->center->categories(),
            'filters' => ['status' => $status, 'category' => $category, 'q' => $search],
        ]);
    }

    /** Ce que la cloche affiche : le nombre de non lues et les plus récentes. */
    public function summary(Request $request): JsonResponse
    {
        if (! $this->center->installed()) {
            return response()->json(['unread' => 0, 'items' => [], 'unavailable' => NotificationCenter::NOT_INSTALLED_MESSAGE]);
        }

        $this->watchPortal($request);

        return response()->json([
            'unread' => $this->center->unreadCount($request->user()),
            'items' => $this->center->latest($request->user()),
        ]);
    }

    /** Ouvrir : la notification est marquée lue, puis on suit son lien — interne seulement. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $found = $this->center->find($request->user(), $notification);
        $this->center->apply($request->user(), [$found->id], 'read');

        return redirect(NotificationCenter::safeUrl($found->data['url'] ?? null) ?? route('notifications.index'));
    }

    public function act(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid'],
            'action' => ['required', Rule::in(NotificationCenter::ACTIONS)],
        ]);

        $changed = $this->center->apply($request->user(), $validated['ids'], $validated['action']);
        $message = match ($validated['action']) {
            'read' => $changed > 1 ? "{$changed} notifications marquées comme lues." : 'Notification marquée comme lue.',
            'unread' => $changed > 1 ? "{$changed} notifications marquées comme non lues." : 'Notification marquée comme non lue.',
            'archive' => $changed > 1 ? "{$changed} notifications archivées." : 'Notification archivée.',
            default => $changed > 1 ? "{$changed} notifications remises dans la liste." : 'Notification remise dans la liste.',
        };

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['changed' => $changed, 'message' => $message, 'unread' => $this->center->unreadCount($request->user())]);
        }

        return back()->with('status', $changed > 0 ? $message : 'Rien à changer.');
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $changed = $this->center->readAll($request->user());
        $message = $changed > 0 ? 'Toutes les notifications sont lues.' : 'Rien à marquer : tout est déjà lu.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['changed' => $changed, 'message' => $message, 'unread' => 0]);
        }

        return back()->with('status', $message);
    }

    /**
     * Portail : les sites ne préviennent pas le portail. Il relit leur API pour
     * savoir si des employés attendent leur accès — après la réponse, au plus une
     * fois par période, pour ne jamais faire attendre la cloche.
     */
    private function watchPortal(Request $request): void
    {
        if (WebmailAccess::onPortal() && $request->user()->can('staff_access.view')) {
            app(StaffAccessWatcher::class)->syncSoon($request->user());
        }
    }
}
