<?php

namespace App\Ai\Agents;

use App\Services\Assistant\AssistantConfiguration;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * ADR-241 — « Rapprocher avec l'IA » : une liste de libellés de produits
 * fournisseurs, et la question « lesquels désignent le même produit ? ».
 *
 * Il ne reçoit que des libellés de catalogue — jamais une donnée de patient —,
 * n'a ni outil ni conversation, et ne décide rien : ses paires reviennent au
 * comparateur comme des propositions qu'un humain confirme ou refuse.
 */
class SupplierProductMatcher implements Agent
{
    use Promptable;

    public function __construct(private readonly AssistantConfiguration $configuration) {}

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
Tu compares des produits pharmaceutiques proposés par plusieurs fournisseurs d'une clinique.
Chaque fournisseur écrit ses produits à sa façon : abréviations (cp = comprimé, inj = injectable, amp = ampoule, sol = solution, fl = flacon…), ordre des mots, unités (500mg = 500 mg = 0,5 g), marque ou nom générique.

Ta tâche : trouver les paires de lignes, de fournisseurs DIFFÉRENTS, qui désignent EXACTEMENT le même produit.
- Même substance, même dosage, même forme, même volume ou calibre, même conditionnement quand il est indiqué.
- Deux dosages, deux volumes, deux tailles ou deux calibres différents font deux produits.
- « stérile » et « non stérile », « avec » et « sans » font deux produits.
- Dans le doute, n'écris pas la paire. Une paire fausse coûte plus cher qu'une paire oubliée.

Réponds uniquement par un objet JSON, sans texte autour :
{"pairs":[{"a":1,"b":2,"why":"raison courte en français"}]}
où a et b sont les numéros des lignes. S'il n'y a aucune paire : {"pairs":[]}
TXT;
    }

    public function provider(): string
    {
        return AssistantConfiguration::PROVIDER_NAME;
    }

    public function model(): ?string
    {
        return $this->configuration->engineModel();
    }

    public function maxTokens(): int
    {
        return max(1000, $this->configuration->maxOutputTokens());
    }

    public function temperature(): ?float
    {
        return 0.0;
    }

    public function timeout(): int
    {
        return max(60, $this->configuration->timeout());
    }
}
