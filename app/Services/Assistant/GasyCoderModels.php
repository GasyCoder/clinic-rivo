<?php

namespace App\Services\Assistant;

/**
 * ADR-222 (amendement ter) — les types de modèle de GasyCoder AI, et l'API de type
 * ChatGPT qui les sert.
 *
 * GasyCoder AI parle le format de ChatGPT (API compatible OpenAI). Sans
 * GASYCODER_AI_URL, il appelle l'API de ChatGPT elle-même ; avec, votre propre API.
 *
 * Le Super Administrateur choisit un type — GasyCoder AI, Mini ou Pro — et c'est ce
 * nom qui est enregistré. Chaque type s'appuie sur un moteur, résolu à chaque appel :
 *
 *   1. celui nommé dans le .env (GASYCODER_AI_MODEL, _MINI, _PRO) ;
 *   2. sinon, avec l'API de ChatGPT, le palier correspondant du SDK Laravel AI
 *      (recommandé, le plus économique, le plus capable) — aucun nom de modèle
 *      n'est écrit dans RIVO, les moteurs suivent les mises à jour du SDK ;
 *   3. sinon, avec votre propre API, le nom du type lui-même (gasycoder-ai-pro…).
 *
 * Un modèle saisi à la main (ex. gpt-…) n'est pas un type : il part tel quel.
 */
final class GasyCoderModels
{
    /** L'adresse de l'API de ChatGPT : celle de GasyCoder AI quand GASYCODER_AI_URL est vide. */
    public const CHATGPT_URL = 'https://api.openai.com/v1';

    /** Où créer une clé ChatGPT, quand GasyCoder AI passe par l'API de ChatGPT. */
    public const CHATGPT_CONSOLE_URL = 'https://platform.openai.com/api-keys';

    public const DEFAULT = 'gasycoder-ai';

    /**
     * Les types proposés, dans l'ordre de la liste. `tier` est le palier du moteur
     * (celui du SDK, et la clé de `config/ai.php` qui le remplace).
     *
     * @var array<string, array{label: string, hint: string, tier: string}>
     */
    public const TYPES = [
        self::DEFAULT => ['label' => 'GasyCoder AI', 'hint' => 'Recommandé', 'tier' => 'default'],
        'gasycoder-ai-mini' => ['label' => 'GasyCoder AI Mini', 'hint' => 'Le plus rapide et le plus économique', 'tier' => 'cheapest'],
        'gasycoder-ai-pro' => ['label' => 'GasyCoder AI Pro', 'hint' => 'Le plus capable', 'tier' => 'smartest'],
    ];

    /** Une API propre est réglée (GASYCODER_AI_URL) ; sinon, c'est celle de ChatGPT. */
    public static function ownApi(): bool
    {
        return self::configuredUrl() !== null;
    }

    public static function url(): string
    {
        return self::configuredUrl() ?? self::CHATGPT_URL;
    }

    public static function consoleUrl(): ?string
    {
        $url = trim((string) config('ai.providers.gasycoder.console_url'));

        if ($url !== '') {
            return $url;
        }

        return self::ownApi() ? null : self::CHATGPT_CONSOLE_URL;
    }

    public static function isType(?string $model): bool
    {
        return $model !== null && array_key_exists($model, self::TYPES);
    }

    public static function label(?string $model): ?string
    {
        return self::isType($model) ? self::TYPES[$model]['label'] : null;
    }

    /**
     * Le moteur réellement appelé pour ce modèle.
     *
     * @param  array{default: ?string, cheapest: ?string, smartest: ?string}  $chatgptTiers  les paliers ChatGPT du SDK
     */
    public static function engineFor(string $model, array $chatgptTiers): string
    {
        if (! self::isType($model)) {
            return $model;
        }

        $tier = self::TYPES[$model]['tier'];
        $configured = trim((string) config('ai.providers.gasycoder.models.text.'.$tier));

        if ($configured !== '') {
            return $configured;
        }

        if (self::ownApi()) {
            return $model;
        }

        // L'API de ChatGPT : le palier du SDK, à défaut le recommandé.
        foreach ([$chatgptTiers[$tier] ?? null, $chatgptTiers['default'] ?? null] as $engine) {
            if (is_string($engine) && $engine !== '') {
                return $engine;
            }
        }

        return $model;
    }

    /**
     * Les types, prêts pour la liste des paramètres : le Super Administrateur voit le
     * moteur de chacun (jamais les comptes qui posent les questions).
     *
     * @param  array{default: ?string, cheapest: ?string, smartest: ?string}  $chatgptTiers
     * @return list<array{value: string, label: string, hint: string, engine: string}>
     */
    public static function options(array $chatgptTiers): array
    {
        return array_map(
            fn (string $value) => [
                'value' => $value,
                'label' => self::TYPES[$value]['label'],
                'hint' => self::TYPES[$value]['hint'],
                'engine' => self::engineFor($value, $chatgptTiers),
            ],
            array_keys(self::TYPES),
        );
    }

    private static function configuredUrl(): ?string
    {
        $url = trim((string) config('ai.providers.gasycoder.url'));

        return $url === '' ? null : rtrim($url, '/');
    }
}
