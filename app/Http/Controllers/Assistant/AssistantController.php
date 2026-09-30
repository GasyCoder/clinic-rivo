<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\AskAssistantRequest;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Assistant\AssistantConversations;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantPageContext;
use App\Services\Assistant\AssistantResponder;
use App\Services\Assistant\AssistantUsageLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-222 — l'assistant d'utilisation du logiciel, sur un site comme sur le portail.
 *
 * La réponse arrive en flux (Server-Sent Events) : chaque morceau part dès que le
 * fournisseur l'envoie. Sur un hébergement mutualisé qui retient la réponse, elle
 * arrive d'un bloc, sans autre différence.
 */
class AssistantController extends Controller
{
    /**
     * Les questions proposées, groupées par module : celui de la page d'où l'on
     * vient, puis ceux du métier du compte — seulement ceux qu'il peut ouvrir.
     */
    public function suggestions(Request $request, AssistantKnowledge $knowledge, AssistantPageContext $context): JsonResponse
    {
        $page = $context->normalize(['path' => (string) $request->query('path', '')]);
        $module = $page['module'] !== null && $knowledge->accessible($page['module'], $request->user()) ? $page['module'] : null;

        return response()->json([
            'module' => $module !== null ? $knowledge->title($module) : null,
            'groups' => $knowledge->suggestionGroups($module, $request->user()),
        ]);
    }

    public function ask(
        AskAssistantRequest $request,
        AssistantConfiguration $configuration,
        AssistantUsageLedger $ledger,
        AssistantConversations $conversations,
        AssistantResponder $responder,
    ): Response {
        $user = $request->user();

        if (! $configuration->available()) {
            return response()->json([
                'reason' => 'unavailable',
                'message' => $configuration->enabled()
                    ? 'L’assistant n’est pas encore configuré. Prévenez l’administrateur.'
                    : 'L’assistant est désactivé pour cet établissement.',
            ], 503);
        }

        if (($refusal = $ledger->refusal($user)) !== null) {
            return response()->json($refusal, 429);
        }

        $conversationId = $request->validated('conversation_id');

        if ($conversationId !== null && ! $conversations->belongsTo($conversationId, $user)) {
            return response()->json(['reason' => 'conversation', 'message' => 'Cette conversation n’existe pas ou ne vous appartient pas.'], 404);
        }

        $events = $responder->stream(
            $user,
            (string) $request->validated('message'),
            $conversationId,
            (array) $request->validated('page', []),
            fn (): bool => connection_aborted() === 1,
        );

        return new StreamedResponse(function () use ($events): void {
            // L'arrêt de l'utilisateur se lit entre deux morceaux : la consommation est
            // enregistrée au lieu que le script soit interrompu en plein flux.
            ignore_user_abort(true);

            // La réponse arrive mot à mot : rien ne doit la retenir en chemin. La compression
            // (zlib, mod_deflate d'Apache chez o2switch) attend d'avoir assez de texte avant
            // d'envoyer — elle est coupée pour ce flux seulement.
            @ini_set('zlib.output_compression', '0');
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }

            // Un commentaire SSE de 2 Ko, ignoré par le navigateur, pousse les tampons des
            // serveurs et des proxys qui retiennent les petites réponses : le premier mot
            // s'affiche dès qu'il est écrit.
            echo ':'.str_repeat(' ', 2048)."\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            foreach ($events as $event) {
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            // Nginx, et le proxy d'o2switch quand il l'honore : ne pas retenir le flux.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
