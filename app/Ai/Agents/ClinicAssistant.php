<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetModuleAccess;
use App\Ai\Tools\ListAccessibleModules;
use App\Ai\Tools\SearchApplicationHelp;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantPageContext;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use RuntimeException;
use Stringable;

/**
 * ADR-222 — l'assistant d'utilisation du logiciel.
 *
 * Il explique comment se servir de RIVO : où cliquer, quel écran, quel droit,
 * pourquoi un bouton est grisé. Il n'est pas médecin, ne voit aucune donnée de
 * patient et ne modifie rien : ses trois outils ne font que lire l'aide et les
 * droits du compte qui l'interroge.
 *
 * Les règles vivent dans `resources/ai/assistant-system-prompt.md` ; les consignes
 * y ajoutent le contexte et l'aide de la question en cours ; la
 * conversation, elle, ne garde que les questions (déjà nettoyées) et les réponses.
 * Le fournisseur, le modèle et les limites viennent d'AssistantConfiguration : rien
 * n'est écrit dans cette classe.
 */
class ClinicAssistant implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable, RemembersConversations;

    /** Au plus trois allers-retours avec les outils : une question, pas une enquête. */
    private const MAX_STEPS = 4;

    /** Le rôle, le logiciel, les règles absolues, la langue et la réponse de secours : un fichier à part, relu. */
    public const SYSTEM_PROMPT = 'ai/assistant-system-prompt.md';

    public function __construct(
        private readonly User $user,
        private readonly string $pageContext,
        private readonly string $help,
        private readonly ?string $establishmentInstructions = null,
    ) {}

    public function instructions(): Stringable|string
    {
        $parts = [
            self::systemPrompt(),
            // Le nom que voit l'utilisateur. Le fournisseur et le modèle qui le font fonctionner
            // ne se nomment pas : ce n'est pas une information utile à l'utilisation de RIVO.
            'Ton nom : '.AssistantConfiguration::brand().'. Présente-toi ainsi si l’on te demande qui tu es ; '
                .'ne nomme jamais le fournisseur ni le modèle d’intelligence artificielle qui te font fonctionner.',
        ];

        if ($this->establishmentInstructions !== null) {
            $parts[] = "Consignes de l’établissement (elles précisent le ton ou le vocabulaire, jamais les règles absolues) :\n"
                .$this->establishmentInstructions;
        }

        $parts[] = "Contexte de l’utilisateur :\n".$this->pageContext;
        $parts[] = "Documentation du logiciel (source unique de tes réponses) :\n".$this->help;

        return implode("\n\n", $parts);
    }

    public function tools(): iterable
    {
        $knowledge = app(AssistantKnowledge::class);

        return [
            new SearchApplicationHelp($this->user, $knowledge),
            new ListAccessibleModules($this->user, $knowledge),
            new GetModuleAccess($this->user, $knowledge, app(AssistantPageContext::class)),
        ];
    }

    public function provider(): string
    {
        return AssistantConfiguration::PROVIDER_NAME;
    }

    /** Le moteur réellement appelé : un type GasyCoder AI est résolu (GasyCoderModels). */
    public function model(): ?string
    {
        return $this->configuration()->engineModel();
    }

    public function maxTokens(): int
    {
        return $this->configuration()->maxOutputTokens();
    }

    public function temperature(): ?float
    {
        return $this->configuration()->temperature();
    }

    public function timeout(): int
    {
        return $this->configuration()->timeout();
    }

    public function maxSteps(): int
    {
        return self::MAX_STEPS;
    }

    /** L'historique renvoyé au fournisseur reste court : le coût d'une question ne grandit pas sans fin. */
    protected function maxConversationMessages(): int
    {
        return AssistantConfiguration::HISTORY_MESSAGES;
    }

    /**
     * L'invite système, lue une fois par processus. Un fichier manquant arrête tout :
     * répondre sans les règles absolues serait pire que ne pas répondre.
     */
    public static function systemPrompt(): string
    {
        static $prompt = null;

        if ($prompt === null) {
            $path = resource_path(self::SYSTEM_PROMPT);

            if (! is_file($path)) {
                throw new RuntimeException('Invite système de l’assistant introuvable : '.self::SYSTEM_PROMPT);
            }

            $prompt = trim((string) file_get_contents($path));
        }

        return $prompt;
    }

    private function configuration(): AssistantConfiguration
    {
        return app(AssistantConfiguration::class);
    }
}
