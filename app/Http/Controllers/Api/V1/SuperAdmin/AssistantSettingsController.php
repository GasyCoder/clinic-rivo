<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Settings\TestAssistantConnectionAction;
use App\Actions\Settings\UpdateAssistantSettingsAction;
use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantSettingsPresenter;
use App\Services\Catalog\CatalogActor;
use App\Support\Settings\AssistantSettingsRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-222 — les réglages de l'assistant de ce site, écrits depuis le portail par
 * l'API (ADR-004, ADR-027). La clé arrive dans le corps de la requête, de serveur à
 * serveur ; elle n'est jamais renvoyée — seulement sa fin masquée.
 */
class AssistantSettingsController extends Controller
{
    public function show(Request $request, AssistantSettingsPresenter $presenter): JsonResponse
    {
        $this->authorizeActor($request, 'ai_settings.view');

        return response()->json(['data' => $presenter->payload()]);
    }

    public function update(Request $request, UpdateAssistantSettingsAction $action, AssistantSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, UpdateAssistantSettingsAction::PERMISSION);
        $validated = $request->validate(AssistantSettingsRules::settings(), AssistantSettingsRules::messages());

        $action->execute($validated, $actor);

        return response()->json([
            'message' => 'Réglages de l’assistant enregistrés pour ce site.',
            'data' => $presenter->payload(),
        ]);
    }

    public function destroyKey(Request $request, UpdateAssistantSettingsAction $action, AssistantSettingsPresenter $presenter): JsonResponse
    {
        $actor = $this->authorizeActor($request, UpdateAssistantSettingsAction::PERMISSION);
        $action->removeKey($actor);

        return response()->json([
            'message' => 'Clé d’API retirée pour ce site.',
            'data' => $presenter->payload(),
        ]);
    }

    public function test(Request $request, TestAssistantConnectionAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, UpdateAssistantSettingsAction::PERMISSION);
        $validated = $request->validate(AssistantSettingsRules::test(), AssistantSettingsRules::messages());

        $result = $action->execute($validated, $actor);

        return response()->json(['message' => $result['message'], 'data' => $result]);
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
