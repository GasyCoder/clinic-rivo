<?php

namespace App\Services\Assistant;

use App\Enums\AssistantProvider;
use App\Support\Settings\AssistantSettingsRules;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ADR-222 — les réglages de l'assistant tels que l'écran du portail les lit, pour
 * un site (par son API) ou pour le portail lui-même.
 *
 * La clé n'y est jamais : seulement si elle existe, d'où elle vient, sa fin masquée
 * (« ••••ABCD ») et la date de son dernier changement.
 */
class AssistantSettingsPresenter
{
    public function __construct(
        private readonly AssistantConfiguration $configuration,
        private readonly AssistantModelCatalog $catalog,
        private readonly AssistantUsageLedger $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $installed = $this->installed();
        $setting = $installed ? $this->configuration->settings() : null;
        $provider = $this->configuration->provider();

        return [
            'installed' => $installed,
            'site' => [
                'code' => config('rivo.site.code'),
                'name' => config('rivo.site.name'),
                'type' => config('rivo.site.type'),
            ],
            'configured_here' => $setting !== null,
            'values' => [
                'enabled' => $setting !== null ? (bool) $setting->enabled : (bool) config('rivo.assistant.enabled', false),
                'provider' => $setting?->provider?->value,
                'model' => $setting?->model,
                'max_output_tokens' => $setting?->max_output_tokens,
                'temperature' => $setting?->temperature === null ? null : (float) $setting->temperature,
                'timeout_seconds' => $setting?->timeout_seconds,
                'rate_limit_per_hour' => $setting?->rate_limit_per_hour,
                'daily_limit_per_user' => $setting?->daily_limit_per_user,
                'monthly_token_budget' => $setting?->monthly_token_budget,
                'instructions' => $setting?->instructions,
            ],
            // Ce qui s'applique quand un champ reste vide : le .env du déploiement.
            'fallbacks' => [
                'provider' => config('rivo.assistant.provider'),
                'model' => config('rivo.assistant.model'),
                'max_output_tokens' => (int) config('rivo.assistant.max_output_tokens', 800),
                'timeout_seconds' => (int) config('rivo.assistant.timeout', 30),
                'rate_limit_per_hour' => (int) config('rivo.assistant.rate_limit_per_hour', 20),
            ],
            'effective' => [
                'provider' => $provider?->value,
                'provider_label' => $provider?->label(),
                'model' => $this->configuration->model(),
                'enabled' => $this->configuration->enabled(),
                'configured' => $this->configuration->configured(),
                'available' => $this->configuration->available(),
            ],
            'key' => [
                'present' => $this->configuration->keySource() !== null,
                'source' => $this->configuration->keySource(),
                'masked' => $this->configuration->maskedKey(),
                'stored_for' => $setting?->api_key ? $setting->provider?->value : null,
                'updated_at' => $setting?->api_key_updated_at?->toIso8601String(),
            ],
            'providers' => collect(AssistantProvider::cases())->map(fn (AssistantProvider $item) => [
                'value' => $item->value,
                'label' => $item->label(),
                'key_url' => $item->keyConsoleUrl(),
                'environment_key' => $item->environmentKey(),
                'models' => $this->catalog->for($item)['options'],
                'default_model' => $this->catalog->defaultFor($item),
            ])->all(),
            'limits' => [
                'min_output_tokens' => AssistantConfiguration::MIN_OUTPUT_TOKENS,
                'max_output_tokens' => AssistantConfiguration::MAX_OUTPUT_TOKENS,
                'question_length' => AssistantConfiguration::MAX_QUESTION_LENGTH,
                'history_messages' => AssistantConfiguration::HISTORY_MESSAGES,
                'instructions_length' => AssistantSettingsRules::INSTRUCTIONS_MAX,
            ],
            'usage' => $installed ? $this->ledger->summary() : ['available' => false],
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedByName(),
        ];
    }

    private function installed(): bool
    {
        try {
            return Schema::hasTable('ai_assistant_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
