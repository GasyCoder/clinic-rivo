<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Models\WebmailLabel;
use App\Services\Webmail\WebmailMailbox;
use App\Services\Webmail\WebmailPresenter;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-194 — la messagerie : la liste d'un dossier, et la lecture d'un message.
 * Tout est lu en direct sur le serveur de messagerie ; rien n'est copié dans RIVO.
 */
class WebmailController extends Controller
{
    /** Les filtres de la liste, écrits dans l'adresse. */
    public const FILTERS = ['non-lus' => 'unseen', 'favoris' => 'flagged'];

    public function index(): RedirectResponse
    {
        return redirect()->route('webmail.folder', ['folder' => 'reception']);
    }

    public function folder(Request $request, string $folder, WebmailMailbox $box, WebmailPresenter $presenter): Response
    {
        $filters = $this->filters($request);

        $label = $filters['label'] !== null
            ? WebmailLabel::query()->where('user_id', $request->user()->id)->where('uuid', $filters['label'])->first()
            : null;
        $criteria = [
            'text' => $filters['q'],
            'unseen' => $filters['filter'] === 'non-lus',
            'flagged' => $filters['filter'] === 'favoris',
            'keyword' => $label?->keyword,
        ];

        // La liste sera lue : sa recherche part avec les compteurs des dossiers, dans le
        // même aller-retour. Un rechargement partiel qui ne la demande pas n'annonce rien.
        if (self::wants($request, 'list')) {
            try {
                $box->expectListing($folder, $criteria);
            } catch (WebmailAuthenticationFailed $exception) {
                throw $exception;
            } catch (WebmailUnavailable) {
                // Dit par la liste elle-même, plus bas.
            }
        }

        // La liste et son erreur éventuelle sont lues ensemble, et seulement si la page
        // les demande : un rechargement partiel des compteurs ne relit pas la liste.
        $loaded = null;
        $load = function () use (&$loaded, $request, $folder, $box, $criteria): array {
            if ($loaded !== null) {
                return $loaded;
            }

            abort_if($box->folder($folder) === null, 404);

            try {
                return $loaded = ['list' => $box->listing($folder, (int) $request->integer('page', 1), $criteria), 'error' => null];
            } catch (WebmailAuthenticationFailed $exception) {
                throw $exception; // mot de passe refusé : redemandé (bootstrap/app.php)
            } catch (WebmailUnavailable $exception) {
                return $loaded = ['list' => ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 1, 'truncated' => false, 'counts' => null], 'error' => $exception->getMessage()];
            }
        };

        return Inertia::render('Webmail/Index', [
            ...$presenter->frame($request->user(), $box),
            'current' => $folder,
            'list' => fn () => $load()['list'],
            'filters' => $filters,
            'message' => null,
            'error' => fn () => $load()['error'],
        ]);
    }

    public function show(Request $request, string $folder, int $uid, WebmailMailbox $box, WebmailPresenter $presenter): Response
    {
        // Le message sera lu : sa lecture, sa position et son marquage « lu » partent
        // avec les compteurs des dossiers, dans le même aller-retour.
        if (self::wants($request, 'message')) {
            $box->expectMessage($folder, $uid);
        }

        $opened = null;
        $open = function () use (&$opened, $request, $folder, $uid, $box): array {
            if ($opened !== null) {
                return $opened;
            }

            abort_if($box->folder($folder) === null || $folder === WebmailMailbox::FAVORITES, 404);

            $message = $box->open($folder, $uid, $request->boolean('images'));
            abort_if($message === null, 404);
            // Le cadre de lecture n'autorise les images distantes que si on les a demandées.
            $message['remote_images'] = $request->boolean('images');

            return $opened = $message;
        };

        return Inertia::render('Webmail/Index', [
            // Le message d'abord : l'ouvrir le marque lu, et les compteurs le reflètent.
            'message' => fn () => $open(),
            ...$presenter->frame($request->user(), $box),
            'current' => $folder,
            'list' => null,
            'filters' => $this->filters($request),
            'error' => null,
        ]);
    }

    /**
     * La page demande-t-elle cette donnée ? Oui pour une visite complète ; pour un
     * rechargement partiel, selon ses listes « only » et « except » (Inertia).
     */
    private static function wants(Request $request, string $prop): bool
    {
        if (! $request->header('X-Inertia-Partial-Component')) {
            return true;
        }

        $only = array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data')));
        $except = array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Except')));

        return ($only === [] || in_array($prop, $only, true)) && ! in_array($prop, $except, true);
    }

    /** @return array{q: ?string, filter: ?string, label: ?string} */
    private function filters(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filtre', '');
        $label = (string) $request->query('libelle', '');

        return [
            'q' => $q !== '' ? mb_substr($q, 0, 100) : null,
            'filter' => array_key_exists($filter, self::FILTERS) ? $filter : null,
            'label' => preg_match('/^[0-9a-f-]{36}$/i', $label) ? $label : null,
        ];
    }
}
