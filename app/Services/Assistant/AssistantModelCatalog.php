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
 *
 * GasyCoder AI propose ses propres types (GasyCoder AI, Mini, Pro), chacun avec le
 * moteur qu'il appelle : par défaut les paliers ChatGPT du SDK (GasyCoderModels).
 */
class AssistantModelCatalog
{
    /** @var array<string, array{default: ?string, options: list<array<string, string>>}> */
    private array $memo = [];

    /** @var array<string, array{default: ?string, cheapest: ?string, smartest: ?string}> */
    private array $tiers = [];

    /** @return array{default: ?string, options: list<array<string, string>>} */
    public function for(AssistantProvider $provider): array
    {
        return $this->memo[$provider->value] ??= $this->read($provider);
    }

    public function defaultFor(AssistantProvider $provider): ?string
    {
        return $this->for($provider)['default'];
    }

    /**
     * Le modèle réellement envoyé au fournisseur : un type GasyCoder AI devient son
     * moteur ; tout autre nom part tel quel.
     */
    public function engineFor(AssistantProvider $provider, string $model): string
    {
        return $provider === AssistantProvider::GasyCoder
            ? GasyCoderModels::engineFor($model, $this->tiers(AssistantProvider::OpenAi))
            : $model;
    }

    /** Le nom lisible d'un modèle proposé (« GasyCoder AI Pro ») ; null pour un nom saisi à la main. */
    public function labelFor(AssistantProvider $provider, ?string $model): ?string
    {
        return $provider === AssistantProvider::GasyCoder ? GasyCoderModels::label($model) : null;
    }

    /** @return array<string, array{default: ?string, options: list<array{value: string, label: string}>}> */
    public function all(): array
    {
        return collect(AssistantProvider::cases())
            ->mapWithKeys(fn (AssistantProvider $provider) => [$provider->value => $this->for($provider)])
            ->all();
    }

    /** @return array{default: ?string, options: list<array<string, string>>} */
    private function read(AssistantProvider $provider): array
    {
        if ($provider === AssistantProvider::GasyCoder) {
            return [
                'default' => GasyCoderModels::DEFAULT,
                'options' => GasyCoderModels::options($this->tiers(AssistantProvider::OpenAi)),
            ];
        }

        $labels = [
            'default' => 'Recommandé par le SDK',
            'cheapest' => 'Le plus économique',
            'smartest' => 'Le plus capable',
        ];
        $models = $this->tiers($provider);

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

    /**
     * Les trois paliers que le SDK connaît pour un fournisseur : recommandé, le plus
     * économique, le plus capable. Construire le fournisseur ne fait aucun appel réseau.
     *
     * @return array{default: ?string, cheapest: ?string, smartest: ?string}
     */
    private function tiers(AssistantProvider $provider): array
    {
        return $this->tiers[$provider->value] ??= $this->readTiers($provider);
    }

    /** @return array{default: ?string, cheapest: ?string, smartest: ?string} */
    private function readTiers(AssistantProvider $provider): array
    {
        $none = ['default' => null, 'cheapest' => null, 'smartest' => null];

        try {
            $instance = app(AiManager::class)->{'create'.Str::studly($provider->driver()).'Driver'}([
                ...(array) config('ai.providers.'.$provider->value, []),
                'name' => 'rivo-model-catalog',
                'driver' => $provider->driver(),
                'key' => 'unused',
            ]);
        } catch (Throwable) {
            return $none;
        }

        if (! $instance instanceof TextProvider) {
            return $none;
        }

        $read = fn (callable $model): ?string => ($value = rescue($model, null, report: false)) && is_string($value) ? $value : null;

        return [
            'default' => $read(fn () => $instance->defaultTextModel()),
            'cheapest' => $read(fn () => $instance->cheapestTextModel()),
            'smartest' => $read(fn () => $instance->smartestTextModel()),
        ];
    }
}
