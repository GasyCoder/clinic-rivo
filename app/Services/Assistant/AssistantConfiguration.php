<?php

namespace App\Services\Assistant;

use App\Enums\AssistantProvider;
use App\Models\AssistantSetting;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\AiManager;

/**
 * ADR-222 — la configuration effective de l'assistant, et le seul endroit qui lit
 * `ai_assistant_settings` et la clé du fournisseur.
 *
 * Chaque valeur se résout dans cet ordre :
 *
 *   1. les paramètres réglés depuis le portail (base de cette base) ;
 *   2. la configuration du déploiement (`config/rivo.php`, et pour la clé la
 *      variable du SDK : OPENAI_API_KEY, ANTHROPIC_API_KEY…) ;
 *   3. sinon l'assistant est « non configuré », et il le dit.
 *
 * La clé déchiffrée ne sort d'ici que vers le SDK, dans la configuration en mémoire
 * de la requête (`registerProvider`) : jamais vers une réponse, un journal ou le
 * navigateur. Liée `scoped` : une lecture de la ligne par requête.
 */
class AssistantConfiguration
{
    /** Le nom du fournisseur que le SDK résout pour l'assistant, isolé de ceux du .env. */
    public const PROVIDER_NAME = 'rivo-assistant';

    public const STATUS_CACHE_KEY = 'rivo:assistant:status';

    public const MAX_QUESTION_LENGTH = 1000;

    /** Les messages de la conversation renvoyés au fournisseur à chaque question : le coût reste borné. */
    public const HISTORY_MESSAGES = 10;

    public const MIN_OUTPUT_TOKENS = 100;

    public const MAX_OUTPUT_TOKENS = 4000;

    /** Le nom de l'assistant quand RIVO_AI_BRAND n'en donne pas d'autre. */
    public const DEFAULT_BRAND = 'GasyCoder AI';

    private bool $loaded = false;

    private ?AssistantSetting $settings = null;

    public function __construct(private readonly AssistantModelCatalog $catalog) {}

    public function settings(): ?AssistantSetting
    {
        if (! $this->loaded) {
            $this->settings = AssistantSetting::current();
            $this->loaded = true;
        }

        return $this->settings;
    }

    public function enabled(): bool
    {
        $settings = $this->settings();

        return $settings !== null ? (bool) $settings->enabled : (bool) config('rivo.assistant.enabled', false);
    }

    /** Le nom de l'assistant dans sa bulle et sa fenêtre (RIVO_AI_BRAND). */
    public static function brand(): string
    {
        $brand = trim((string) config('rivo.assistant.brand'));

        return $brand !== '' ? $brand : self::DEFAULT_BRAND;
    }

    public function provider(): ?AssistantProvider
    {
        return $this->settings()?->provider ?? AssistantProvider::tryFrom((string) config('rivo.assistant.provider'));
    }

    public function model(): ?string
    {
        $provider = $this->provider();
        $configured = trim((string) ($this->settings()?->model ?: config('rivo.assistant.model')));

        if ($configured !== '') {
            return $configured;
        }

        return $provider ? $this->catalog->defaultFor($provider) : null;
    }

    /**
     * Le modèle réellement envoyé au fournisseur : un type GasyCoder AI (« GasyCoder AI
     * Pro ») devient son moteur ; tout autre nom part tel quel.
     */
    public function engineModel(): ?string
    {
        $provider = $this->provider();
        $model = $this->model();

        return $provider && filled($model) ? $this->catalog->engineFor($provider, (string) $model) : $model;
    }

    /** Le nom lisible du modèle choisi (« GasyCoder AI Pro ») ; null pour un nom saisi à la main. */
    public function modelLabel(): ?string
    {
        $provider = $this->provider();

        return $provider ? $this->catalog->labelFor($provider, $this->model()) : null;
    }

    /** D'où vient la clé : `database` (portail), `environment` (.env) ou null (aucune). */
    public function keySource(): ?string
    {
        if (filled($this->settings()?->api_key)) {
            return 'database';
        }

        return filled($this->environmentKey()) ? 'environment' : null;
    }

    /** La clé masquée, pour l'affichage : « ••••ABCD ». Jamais la clé elle-même. */
    public function maskedKey(): ?string
    {
        $key = $this->apiKey();

        return $key === null ? null : self::mask($key);
    }

    public static function mask(string $key): string
    {
        return str_repeat('•', 16).mb_substr($key, -4);
    }

    public function configured(): bool
    {
        $provider = $this->provider();

        return $provider !== null
            && $this->apiKey() !== null
            && filled($this->model());
    }

    public function available(): bool
    {
        return $this->enabled() && $this->configured();
    }

    /**
     * L'état qu'affiche chaque page (bouton de l'assistant) : mis en cache, pour ne pas
     * relire la base à chaque requête. Oublié à chaque enregistrement des réglages.
     *
     * @return array{enabled: bool, configured: bool, available: bool}
     */
    public function status(): array
    {
        return Cache::remember(self::STATUS_CACHE_KEY, now()->addMinutes(10), fn () => [
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'available' => $this->available(),
        ]);
    }

    public function forget(): void
    {
        Cache::forget(self::STATUS_CACHE_KEY);
        $this->loaded = false;
        $this->settings = null;
    }

    public function maxOutputTokens(): int
    {
        $value = (int) ($this->settings()?->max_output_tokens ?: config('rivo.assistant.max_output_tokens', 800));

        return max(self::MIN_OUTPUT_TOKENS, min(self::MAX_OUTPUT_TOKENS, $value));
    }

    public function temperature(): ?float
    {
        $value = $this->settings()?->temperature;

        return $value === null ? null : (float) $value;
    }

    public function timeout(): int
    {
        return max(5, min(120, (int) ($this->settings()?->timeout_seconds ?: config('rivo.assistant.timeout', 30))));
    }

    public function rateLimitPerHour(): int
    {
        return max(1, (int) ($this->settings()?->rate_limit_per_hour ?: config('rivo.assistant.rate_limit_per_hour', 20)));
    }

    public function dailyLimitPerUser(): ?int
    {
        $value = $this->settings()?->daily_limit_per_user;

        return $value ? (int) $value : null;
    }

    public function monthlyTokenBudget(): ?int
    {
        $value = $this->settings()?->monthly_token_budget;

        return $value ? (int) $value : null;
    }

    /** Les consignes ajoutées par le Super Administrateur, en plus des règles fixes. */
    public function instructions(): ?string
    {
        $value = trim((string) $this->settings()?->instructions);

        return $value === '' ? null : $value;
    }

    /**
     * Déclare au SDK le fournisseur de l'assistant, avec sa clé, pour la durée de la
     * requête. Le nom est propre à RIVO : il ne touche pas aux fournisseurs du .env.
     *
     * `$model` peut être un type GasyCoder AI : c'est son moteur qui est déclaré, et
     * l'adresse de GasyCoder AI (la vôtre, sinon celle de ChatGPT) l'accompagne.
     */
    public function registerProvider(?AssistantProvider $provider = null, ?string $key = null, ?string $model = null, string $name = self::PROVIDER_NAME): string
    {
        $provider ??= $this->provider();
        $key ??= $this->apiKey();
        $model ??= $this->model();

        if ($provider !== null && filled($model)) {
            $model = $this->catalog->engineFor($provider, (string) $model);
        }

        $base = (array) config('ai.providers.'.$provider?->value, []);
        $models = $model ? ['text' => ['default' => $model, 'cheapest' => $model, 'smartest' => $model]] : ($base['models'] ?? []);

        if ($provider?->apiUrl() !== null) {
            $base['url'] = $provider->apiUrl();
        }

        config(['ai.providers.'.$name => [...$base, 'driver' => $provider?->driver(), 'key' => (string) $key, 'models' => $models]]);
        app(AiManager::class)->forgetInstance($name);

        return $name;
    }

    /** La clé déchiffrée. Privée par intention : seul registerProvider la transmet, au SDK. */
    private function apiKey(): ?string
    {
        $stored = $this->settings()?->api_key;

        if (filled($stored)) {
            return (string) $stored;
        }

        return $this->environmentKey();
    }

    private function environmentKey(): ?string
    {
        $provider = $this->provider();
        $key = $provider ? trim((string) config('ai.providers.'.$provider->value.'.key')) : '';

        return $key === '' ? null : $key;
    }
}
