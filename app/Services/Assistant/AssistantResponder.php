<?php

namespace App\Services\Assistant;

use App\Ai\Agents\ClinicAssistant;
use App\Models\AssistantUsage;
use App\Models\User;
use Generator;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Throwable;

/**
 * ADR-222 — une question posée à l'assistant, de bout en bout :
 *
 *   1. la question est nettoyée (PromptRedactor) — c'est elle qui part et reste ;
 *   2. le contexte de la page et l'aide des modules que le compte peut ouvrir sont
 *      joints aux consignes, jamais à la conversation ;
 *   3. le fournisseur réglé est déclaré au SDK, clé comprise, pour cette requête ;
 *   4. la réponse est relayée morceau par morceau ;
 *   5. la consommation est enregistrée, réussie, arrêtée ou non ;
 *   6. deux ou trois questions de suivi sont proposées.
 *
 * Les vérifications qui refusent sans rien appeler (assistant désactivé, quota,
 * conversation d'un autre compte) sont faites avant, par le contrôleur : une
 * réponse HTTP d'erreur ordinaire, que l'écran lit simplement.
 */
class AssistantResponder
{
    public function __construct(
        private readonly AssistantConfiguration $configuration,
        private readonly AssistantKnowledge $knowledge,
        private readonly AssistantPageContext $context,
        private readonly PromptRedactor $redactor,
        private readonly AssistantUsageLedger $ledger,
    ) {}

    /**
     * Les événements de la réponse : `meta`, `status`, `delta`, puis `done` ou `error`.
     * `$shouldStop` est interrogé entre deux morceaux : l'utilisateur a arrêté.
     *
     * @param  array{path?: ?string, component?: ?string, section?: ?string}  $page
     * @param  (callable(): bool)|null  $shouldStop
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(User $user, string $question, ?string $conversationId, array $page, ?callable $shouldStop = null): Generator
    {
        $redacted = $this->redactor->redact(mb_substr($question, 0, AssistantConfiguration::MAX_QUESTION_LENGTH));
        $page = $this->context->normalize($page);

        $agent = new ClinicAssistant(
            $user,
            $this->context->describe($user, $page),
            $this->knowledge->relevantHelp($page['module'], $redacted['text'], $user),
            $this->configuration->instructions(),
        );

        $conversationId === null ? $agent->forUser($user) : $agent->continue($conversationId, $user);

        $provider = (string) $this->configuration->provider()?->value;
        // Le moteur réellement facturé par le fournisseur (un type GasyCoder AI est résolu).
        $model = (string) $this->configuration->engineModel();
        $started = hrtime(true);
        $elapsed = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        yield ['type' => 'meta', 'redacted' => $redacted['redactions'] > 0, 'question' => $redacted['text']];

        try {
            $this->configuration->registerProvider();
            $response = $agent->stream($redacted['text']);

            if ($response->conversationId !== null) {
                yield ['type' => 'conversation', 'id' => $response->conversationId];
            }

            foreach ($response as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
                    yield ['type' => 'delta', 'text' => $event->delta];
                } elseif ($event instanceof ToolCall) {
                    yield ['type' => 'status', 'text' => 'Recherche dans l’aide du logiciel…'];
                }

                if ($shouldStop !== null && $shouldStop()) {
                    $this->ledger->record($user, $response->conversationId ?? $conversationId, $provider, $model, AssistantUsage::STATUS_STOPPED, null, null, $elapsed());

                    return;
                }
            }

            $this->ledger->record($user, $response->conversationId, $provider, $model, AssistantUsage::STATUS_COMPLETED, null, $response->usage, $elapsed());

            yield [
                'type' => 'done',
                'conversation_id' => $response->conversationId,
                // Deux ou trois questions de suivi, prises dans l'aide des modules que le
                // compte peut ouvrir : l'assistant sait y répondre.
                'follow_ups' => $this->knowledge->followUps($redacted['text'], $page['module'], $user),
                'usage' => [
                    'input_tokens' => (int) ($response->usage?->inputTokens ?? 0),
                    'output_tokens' => (int) ($response->usage?->outputTokens ?? 0),
                ],
            ];
        } catch (Throwable $exception) {
            $error = AssistantErrors::describe($exception);

            AssistantErrors::log($exception, $error, $provider, $model);

            $this->ledger->record($user, $conversationId, $provider, $model, AssistantUsage::STATUS_FAILED, $error['reason'], null, $elapsed());

            yield ['type' => 'error', 'reason' => $error['reason'], 'message' => $error['message']];
        }
    }
}
