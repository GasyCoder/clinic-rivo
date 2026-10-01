<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * ADR-222 — « Tester la connexion » : une question minuscule, sans conversation ni
 * outil ni donnée, pour vérifier que le fournisseur, le modèle et la clé répondent.
 * Quelques tokens au plus.
 */
class AssistantConnectionCheck implements Agent
{
    use Promptable;

    public const PROMPT = 'Réponds uniquement par le mot : OK';

    public function __construct(
        private readonly string $providerName,
        private readonly ?string $modelName,
        private readonly int $timeoutSeconds,
    ) {}

    public function instructions(): Stringable|string
    {
        return 'Tu réponds uniquement par le mot OK.';
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): ?string
    {
        return $this->modelName;
    }

    public function maxTokens(): int
    {
        return 16;
    }

    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }
}
