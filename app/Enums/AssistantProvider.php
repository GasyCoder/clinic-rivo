<?php

namespace App\Enums;

use App\Services\Assistant\GasyCoderModels;

/**
 * ADR-222 — les fournisseurs que l'assistant sait appeler, par le SDK Laravel AI.
 *
 * Chaque valeur est le nom du pilote du SDK (`config/ai.php`) : ajouter un
 * fournisseur que le SDK connaît déjà, c'est ajouter une ligne ici. Aucun nom de
 * modèle n'est écrit dans ce fichier — les modèles proposés sont lus dans le SDK
 * (AssistantModelCatalog), et le Super Administrateur peut en saisir un autre.
 *
 * Seuls des fournisseurs à simple clé d'API sont proposés : Azure, Bedrock ou
 * Ollama demandent une infrastructure que l'hébergement de la clinique n'a pas.
 *
 * GasyCoder AI est un fournisseur comme les autres — un modèle, une clé — de type
 * ChatGPT : le pilote « compatible OpenAI » du SDK, pointé sur l'API de ChatGPT, ou
 * sur votre propre API si GASYCODER_AI_URL la nomme. Ses types de modèle (GasyCoder
 * AI, Mini, Pro) et leurs moteurs sont dans App\Services\Assistant\GasyCoderModels.
 */
enum AssistantProvider: string
{
    case GasyCoder = 'gasycoder';
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case Mistral = 'mistral';
    case DeepSeek = 'deepseek';
    case Groq = 'groq';
    case Xai = 'xai';

    public function label(): string
    {
        return match ($this) {
            self::GasyCoder => 'GasyCoder AI',
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic (Claude)',
            self::Gemini => 'Google Gemini',
            self::OpenRouter => 'OpenRouter',
            self::Mistral => 'Mistral AI',
            self::DeepSeek => 'DeepSeek',
            self::Groq => 'Groq',
            self::Xai => 'xAI (Grok)',
        };
    }

    /** Le pilote du SDK qui l'appelle : son propre nom, sauf GasyCoder AI (compatible OpenAI). */
    public function driver(): string
    {
        return match ($this) {
            self::GasyCoder => 'openai-compatible',
            default => $this->value,
        };
    }

    /**
     * L'adresse de l'API de GasyCoder AI : la vôtre (GASYCODER_AI_URL), sinon celle de
     * ChatGPT. Null pour les autres : le SDK connaît la leur.
     */
    public function apiUrl(): ?string
    {
        return $this === self::GasyCoder ? GasyCoderModels::url() : null;
    }

    /** Où le Super Administrateur crée la clé : un repère, jamais un appel. */
    public function keyConsoleUrl(): ?string
    {
        return match ($this) {
            self::GasyCoder => GasyCoderModels::consoleUrl(),
            self::OpenAi => 'https://platform.openai.com/api-keys',
            self::Anthropic => 'https://console.anthropic.com/settings/keys',
            self::Gemini => 'https://aistudio.google.com/app/apikey',
            self::OpenRouter => 'https://openrouter.ai/keys',
            self::Mistral => 'https://console.mistral.ai/api-keys',
            self::DeepSeek => 'https://platform.deepseek.com/api_keys',
            self::Groq => 'https://console.groq.com/keys',
            self::Xai => 'https://console.x.ai',
        };
    }

    /** La variable d'environnement du SDK qui sert de secours (ADR-222). */
    public function environmentKey(): string
    {
        return match ($this) {
            self::GasyCoder => 'GASYCODER_AI_API_KEY',
            self::OpenAi => 'OPENAI_API_KEY',
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::Gemini => 'GEMINI_API_KEY',
            self::OpenRouter => 'OPENROUTER_API_KEY',
            self::Mistral => 'MISTRAL_API_KEY',
            self::DeepSeek => 'DEEPSEEK_API_KEY',
            self::Groq => 'GROQ_API_KEY',
            self::Xai => 'XAI_API_KEY',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $provider) => $provider->value, self::cases());
    }
}
