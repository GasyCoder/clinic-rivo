<?php

namespace App\Services\Assistant;

use App\Enums\AssistantProvider;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Throwable;

/**
 * ADR-222 — les modèles proposés pour chaque fournisseur, lus dans le SDK Laravel AI
 * lui-même : son modèle par défaut, le plus économique et le plus capable.
 *
 * Aucun nom de modèle n'est écrit dans RIVO. Quand le SDK est mis à jour, les
 * propositions suivent ; un modèle absent de la liste reste saisissable à la main
 * dans les paramètres. Construire un fournisseur ne fait aucun appel réseau.
 */
class AssistantModelCatalog
{
    /** @var array<string, array{default: ?string, options: list<array{value: string, label: string}>}> */
    private array $memo = [];

    /** @return array{default: ?string, options: list<array{value: string, label: string}>} */
    public function for(AssistantProvider $provider): array
    {
        return $this->memo[$provider->value] ??= $this->read($provider);
    }

    public function defaultFor(AssistantProvider $provider): ?string
    {
        return $this->for($provider)['default'];
    }

    /** @return array<string, array{default: ?string, options: list<array{value: string, label: string}>}> */
    public function all(): array
    {
        return collect(AssistantProvider::cases())
            ->mapWithKeys(fn (AssistantProvider $provider) => [$provider->value => $this->for($provider)])
            ->all();
    }

    /** @return array{default: ?string, options: list<array{value: string, label: string}>} */
    private function read(AssistantProvider $provider): array
    {
        try {
            $instance = app(AiManager::class)->{'create'.Str::studly($provider->value).'Driver'}([
                ...(array) config('ai.providers.'.$provider->value, []),
                'name' => 'rivo-model-catalog',
                'driver' => $provider->value,
                'key' => 'unused',
            ]);
        } catch (Throwable) {
            return ['default' => null, 'options' => []];
        }

        if (! $instance instanceof TextProvider) {
            return ['default' => null, 'options' => []];
        }

        $labels = [
            'default' => 'Recommandé par le SDK',
            'cheapest' => 'Le plus économique',
            'smartest' => 'Le plus capable',
        ];
        $models = [
            'default' => rescue(fn () => $instance->defaultTextModel(), null, report: false),
            'cheapest' => rescue(fn () => $instance->cheapestTextModel(), null, report: false),
            'smartest' => rescue(fn () => $instance->smartestTextModel(), null, report: false),
        ];

        $options = [];

        foreach ($models as $kind => $model) {
            if (! is_string($model) || $model === '') {
                continue;
            }

            if (isset($options[$model])) {
                $options[$model]['label'] .= ' · '.mb_strtolower($labels[$kind]);

                continue;
            }

            $options[$model] = ['value' => $model, 'label' => $labels[$kind]];
        }

        return ['default' => $models['default'] ?: null, 'options' => array_values($options)];
    }
}
