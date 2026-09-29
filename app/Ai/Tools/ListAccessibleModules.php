<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Assistant\AssistantKnowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** ADR-222 — les modules que ce compte peut ouvrir, et où les trouver. Lecture seule. */
class ListAccessibleModules implements Tool
{
    public function __construct(
        private readonly User $user,
        private readonly AssistantKnowledge $knowledge,
    ) {}

    public function description(): Stringable|string
    {
        return 'Liste les modules du logiciel que cet utilisateur peut ouvrir, avec leur adresse. '
            .'À utiliser pour répondre à « où trouver… » ou « à quoi ai-je accès ».';
    }

    public function handle(Request $request): Stringable|string
    {
        $lines = collect($this->knowledge->accessibleModules($this->user))
            ->reject(fn (string $module) => $module === 'general')
            ->map(function (string $module): string {
                $path = $this->knowledge->entryPath($module);

                return '- '.$this->knowledge->title($module).($path ? ' (adresse '.$path.')' : '');
            });

        return $lines->isEmpty()
            ? 'Ce compte n’ouvre aucun module métier : il faut demander des droits à l’administrateur.'
            : "Modules que ce compte peut ouvrir :\n".$lines->implode("\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
