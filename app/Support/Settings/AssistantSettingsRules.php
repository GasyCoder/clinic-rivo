<?php

namespace App\Support\Settings;

use App\Enums\AssistantProvider;
use App\Services\Assistant\AssistantConfiguration;
use Illuminate\Validation\Rule;

/**
 * ADR-222 — les règles des réglages de l'assistant, les mêmes au portail et sur
 * l'API d'un site. La clé n'est jamais obligatoire : un champ laissé vide garde la
 * clé enregistrée ; la retirer est un geste à part.
 */
class AssistantSettingsRules
{
    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/@+\-]+$/';

    public const INSTRUCTIONS_MAX = 2000;

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'provider' => ['nullable', Rule::enum(AssistantProvider::class)],
            'model' => ['nullable', 'string', 'max:150', 'regex:'.self::MODEL_PATTERN],
            ...self::key(),
            'max_output_tokens' => ['nullable', 'integer', 'min:'.AssistantConfiguration::MIN_OUTPUT_TOKENS, 'max:'.AssistantConfiguration::MAX_OUTPUT_TOKENS],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            'rate_limit_per_hour' => ['nullable', 'integer', 'min:1', 'max:500'],
            'daily_limit_per_user' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'instructions' => ['nullable', 'string', 'max:'.self::INSTRUCTIONS_MAX],
        ];
    }

    /** « Tester la connexion » : les valeurs du formulaire, même non enregistrées. @return array<string, mixed> */
    public static function test(): array
    {
        return [
            'provider' => ['required', Rule::enum(AssistantProvider::class)],
            'model' => ['nullable', 'string', 'max:150', 'regex:'.self::MODEL_PATTERN],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            ...self::key(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'provider.enum' => 'Choisissez un fournisseur de la liste.',
            'provider.required' => 'Choisissez un fournisseur.',
            'model.regex' => 'Le nom du modèle ne contient que des lettres, des chiffres et . _ - : / @ +.',
            'api_key.not_regex' => 'La clé ne contient ni espace ni retour à la ligne : vérifiez le copier-coller.',
            'api_key.min' => 'Cette clé est trop courte pour être une clé d’API.',
            'api_key.required_with' => 'Saisissez la clé du fournisseur.',
            'max_output_tokens.min' => 'Au moins '.AssistantConfiguration::MIN_OUTPUT_TOKENS.' tokens par réponse.',
            'max_output_tokens.max' => 'Au plus '.AssistantConfiguration::MAX_OUTPUT_TOKENS.' tokens par réponse.',
            'temperature.max' => 'La température va de 0 à 2.',
            'timeout_seconds.min' => 'Le délai d’attente va de 5 à 120 secondes.',
            'timeout_seconds.max' => 'Le délai d’attente va de 5 à 120 secondes.',
            'monthly_token_budget.min' => 'Un budget mensuel compte au moins 1 000 tokens.',
            'instructions.max' => 'Les consignes tiennent en '.self::INSTRUCTIONS_MAX.' caractères au plus.',
        ];
    }

    /** @return array<string, mixed> */
    private static function key(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'min:12', 'max:500', 'not_regex:/\s/'],
        ];
    }
}
