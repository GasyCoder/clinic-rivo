<?php

namespace App\Http\Middleware;

use App\Services\Webmail\MailServerFactory;
use App\Services\Webmail\WebmailAccess;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailMailbox;
use App\Services\Webmail\WebmailSession;
use App\Services\Webmail\WebmailUnavailable;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-195 — remet aux contrôleurs la boîte choisie, avec le mot de passe gardé
 * dans la session. Sans mot de passe : la page d'ouverture.
 *
 * La connexion au serveur n'a lieu qu'au premier usage (LazyMailServer) : une
 * requête qui ne lit rien — un rechargement partiel — ne la paie pas. Un mot de
 * passe que le serveur refuse désormais est oublié et redemandé ; un serveur
 * injoignable le dit, sans erreur brute. La boîte est fermée en fin de requête.
 */
class OpenWebmailMailbox
{
    public function __construct(
        private readonly WebmailAccess $access,
        private readonly WebmailSession $session,
        private readonly MailServerFactory $factory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mailbox = $this->access->current($request->user());
        $password = $this->session->passwordFor($mailbox);

        if ($mailbox === null || $password === null) {
            // Un envoi d'arrière-plan : l'écran garde le message et dit de rouvrir la boîte.
            if (self::inBackground($request)) {
                return response()->json(['message' => 'La boîte s’est refermée : rouvrez-la, votre message est gardé.', 'reconnect' => route('webmail.connect')], 409);
            }

            // Retenue : une fois la boîte ouverte, on revient au message demandé.
            return redirect()->guest(route('webmail.connect'));
        }

        try {
            $server = $this->factory->open($mailbox->address, $password);
        } catch (WebmailUnavailable $exception) {
            return self::failure($request, $exception, $this->session);
        }

        app()->instance(WebmailMailbox::class, new WebmailMailbox($server, $mailbox));

        try {
            return $next($request);
        } catch (WebmailUnavailable $exception) {
            return self::failure($request, $exception, $this->session);
        } finally {
            $server->disconnect();
        }
    }

    /**
     * Le serveur refuse le mot de passe : il est oublié, et redemandé. Il ne répond
     * pas : une page le dit (lecture), ou le formulaire garde sa saisie (écriture).
     */
    public static function failure(Request $request, WebmailUnavailable $exception, WebmailSession $session): Response
    {
        if ($exception instanceof WebmailAuthenticationFailed) {
            $box = $session->box();
            $session->forget();

            if (self::inBackground($request)) {
                return response()->json([
                    'message' => 'Le serveur de messagerie refuse désormais ce mot de passe : rouvrez la boîte, votre message est gardé.',
                    'reconnect' => route('webmail.connect'),
                ], 409);
            }

            return redirect()->route('webmail.connect')->withErrors([
                'password' => $box === null || $box->own
                    ? 'Le mot de passe de votre boîte a changé : saisissez-le de nouveau.'
                    : 'Le serveur de messagerie refuse désormais ce mot de passe (changé, ou adresse suspendue).',
            ]);
        }

        if ($request->isMethod('GET') && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return Inertia::render('Webmail/Offline', ['message' => $exception->getMessage(), 'address' => $session->box()?->address])
                ->toResponse($request)
                ->setStatusCode(503);
        }

        if (self::inBackground($request)) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['webmail' => [$exception->getMessage()]]], 503);
        }

        return back()->withErrors(['webmail' => $exception->getMessage()]);
    }

    /** Une requête d'arrière-plan (fetch) attend du JSON, pas une page ni une redirection. */
    private static function inBackground(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
