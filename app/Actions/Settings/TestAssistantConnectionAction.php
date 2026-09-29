<?php

namespace App\Actions\Settings;

use App\Ai\Agents\AssistantConnectionCheck;
use App\Enums\AssistantProvider;
use App\Models\AssistantSetting;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Assistant\AssistantErrors;
use App\Services\Assistant\AssistantModelCatalog;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * ADR-222 — « Tester la connexion » : les valeurs du formulaire, même avant de les
 * enregistrer, contre le vrai fournisseur. Une question de quelques tokens, sans
 * donnée ni conversation ni outil.
 *
 * La clé essayée est, dans l'ordre : celle qu'on vient de saisir ; sinon celle qui
 * est enregistrée, si elle appartient au même fournisseur ; sinon celle du .env.
 * Elle ne part que vers le fournisseur ; le résultat n'en dit rien, sinon d'où elle
 * vient. Chaque essai est audité, sans la clé.
 */
class TestAssistantConnectionAction
{
    private const PROVIDER_NAME = 'rivo-assistant-test';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AssistantConfiguration $configuration,
        private readonly AssistantModelCatalog $catalog,
    ) {}

    /**
     * @param  array{provider: string, model?: ?string, api_key?: ?string, timeout_seconds?: ?int}  $data
     * @return array{ok: bool, message: string, reason: ?string, status: ?int, latency_ms: int, provider: string, model: ?string, key_source: ?string}
     */
    public function execute(array $data, CatalogActor $actor): array
    {
        if ($actor->cannot(UpdateAssistantSettingsAction::PERMISSION)) {
            throw new AuthorizationException('Vous ne pouvez pas tester l’assistant IA.');
        }

        $provider = AssistantProvider::from((string) $data['provider']);
        $model = filled($data['model'] ?? null) ? trim((string) $data['model']) : $this->catalog->defaultFor($provider);
        [$key, $source] = $this->key($provider, trim((string) ($data['api_key'] ?? '')));
        $timeout = max(5, min(120, (int) ($data['timeout_seconds'] ?? $this->configuration->timeout())));

        $result = ['provider' => $provider->label(), 'model' => $model, 'key_source' => $source];

        if ($key === null) {
            $result += ['ok' => false, 'reason' => 'no_key', 'status' => null, 'latency_ms' => 0, 'message' => 'Aucune clé d’API pour '.$provider->label().' : saisissez-la avant de tester.'];
        } elseif ($model === null) {
            $result += ['ok' => false, 'reason' => 'model', 'status' => null, 'latency_ms' => 0, 'message' => 'Aucun modèle proposé pour ce fournisseur : saisissez le nom du modèle.'];
        } else {
            $result += $this->probe($provider, $key, $model, $timeout);
        }

        $this->auditor->record(
            'ai_settings.test',
            newValues: [
                'provider' => $provider->value,
                'model' => $model,
                'key_source' => $source,
                'ok' => $result['ok'],
                'reason' => $result['reason'],
            ],
            module: 'settings',
        );

        return $result;
    }

    /** @return array{ok: bool, message: string, reason: ?string, status: ?int, latency_ms: int} */
    private function probe(AssistantProvider $provider, string $key, string $model, int $timeout): array
    {
        $name = $this->configuration->registerProvider($provider, $key, $model, self::PROVIDER_NAME);
        $started = hrtime(true);

        try {
            (new AssistantConnectionCheck($name, $model, $timeout))->prompt(AssistantConnectionCheck::PROMPT);
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);

            return [
                'ok' => true,
                'reason' => null,
                'status' => null,
                'latency_ms' => $latency,
                'message' => 'Connexion réussie : '.$provider->label().' a répondu avec le modèle '.$model.' en '.number_format($latency / 1000, 1, ',', ' ').' s.',
            ];
        } catch (Throwable $exception) {
            $error = AssistantErrors::describe($exception);
            AssistantErrors::log($exception, $error, $provider->value, $model);

            return [
                'ok' => false,
                'reason' => $error['reason'],
                'status' => $error['status'],
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => $this->adminMessage($error['reason'], $error['status'], $provider),
            ];
        } finally {
            config(['ai.providers.'.self::PROVIDER_NAME => null]);
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function key(AssistantProvider $provider, string $typed): array
    {
        if ($typed !== '') {
            return [$typed, 'typed'];
        }

        $stored = AssistantSetting::current();

        if ($stored !== null && filled($stored->api_key) && $stored->provider === $provider) {
            return [(string) $stored->api_key, 'database'];
        }

        $environment = trim((string) config('ai.providers.'.$provider->value.'.key'));

        return $environment !== '' ? [$environment, 'environment'] : [null, null];
    }

    /** Le Super Administrateur a besoin de savoir quoi corriger : le code HTTP l'y aide. */
    private function adminMessage(string $reason, ?int $status, AssistantProvider $provider): string
    {
        $suffix = $status !== null ? " (code {$status})" : '';

        return match ($reason) {
            'auth' => 'Clé refusée par '.$provider->label().$suffix.' : vérifiez qu’elle est complète, active et pour ce fournisseur.',
            'model' => 'Modèle introuvable chez '.$provider->label().$suffix.' : vérifiez son nom exact.',
            'credits' => 'Le compte '.$provider->label().' n’a plus de crédit'.$suffix.'.',
            'provider_rate_limited' => $provider->label().' limite les demandes de ce compte'.$suffix.' : réessayez plus tard.',
            'connection' => $provider->label().' ne répond pas : délai dépassé, réseau ou pare-feu de l’hébergement (appels sortants en HTTPS).',
            'overloaded' => $provider->label().' est surchargé'.$suffix.' : réessayez dans quelques minutes.',
            'request' => $provider->label().' a refusé la demande'.$suffix.' : vérifiez le modèle et les réglages (certains modèles refusent la température).',
            default => 'Le test a échoué'.$suffix.'. Consultez le journal du serveur (catégorie sans détail sensible).',
        };
    }
}
