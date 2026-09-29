<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * ADR-222 — chercher dans l'aide du logiciel. Lecture seule : l'outil ne lit que
 * les fiches de `resources/ai/clinic-assistant`, et seulement celles des modules
 * que le compte peut ouvrir.
 */
class SearchApplicationHelp implements Tool
{
    public function __construct(
        private readonly User $user,
        private readonly AssistantKnowledge $knowledge,
    ) {}

    public function description(): Stringable|string
    {
        return 'Cherche dans la documentation du logiciel RIVO les sections qui expliquent une fonctionnalité '
            .'(menus, boutons, étapes, droits). À utiliser quand la documentation jointe ne répond pas à la question. '
            .'Ne renvoie que les modules que cet utilisateur peut ouvrir.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = mb_substr(trim((string) $request->string('query')), 0, 200);

        if ($query === '') {
            return 'Indiquez les mots de la fonctionnalité recherchée.';
        }

        $sections = $this->knowledge->search($query, $this->user, 4);

        if ($sections === []) {
            return 'Aucune section de la documentation ne correspond, parmi les modules que ce compte peut ouvrir. '
                .'Dites-le à l’utilisateur au lieu d’inventer une réponse.';
        }

        return collect($sections)
            ->map(fn (array $section) => '## '.$this->knowledge->title($section['module']).' — '.$section['heading']."\n".$section['text'])
            ->implode("\n\n---\n\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Les mots-clés de la fonctionnalité recherchée, en français (ex. « délivrer ordonnance pharmacie »).')
                ->max(200)
                ->required(),
        ];
    }
}
