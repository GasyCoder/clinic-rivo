<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantConversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-222 — l'historique des conversations avec l'assistant. Chacun ne voit, ne
 * reprend et n'efface que les siennes : une conversation d'un autre compte répond
 * « introuvable », sans dire qu'elle existe.
 */
class AssistantConversationController extends Controller
{
    public function index(Request $request, AssistantConversations $conversations): JsonResponse
    {
        return response()->json(['conversations' => $conversations->recent($request->user())]);
    }

    public function show(Request $request, string $conversation, AssistantConversations $conversations): JsonResponse
    {
        abort_unless($conversations->belongsTo($conversation, $request->user()), 404);

        return response()->json($conversations->transcript($conversation));
    }

    public function destroy(Request $request, string $conversation, AssistantConversations $conversations): JsonResponse
    {
        abort_unless($conversations->belongsTo($conversation, $request->user()), 404);

        $conversations->delete($conversation);

        return response()->json(['message' => 'Conversation supprimée.']);
    }
}
