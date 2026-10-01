<?php

namespace App\Ai\Tools;

use App\Models\Permission;
use App\Models\User;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantPageContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * ADR-222 — ce que ce compte peut faire dans un module : les droits qu'il a, ceux
 * qui lui manquent. Lecture seule, et seulement pour ce compte : c'est la réponse
 * à « pourquoi ce bouton est grisé ? », déjà dite par l'application (ADR-154).
 */
class GetModuleAccess implements Tool
{
    public function __construct(
        private readonly User $user,
        private readonly AssistantKnowledge $knowledge,
        private readonly AssistantPageContext $context,
    ) {}

    public function description(): Stringable|string
    {
        return 'Donne les droits que cet utilisateur a, et ceux qui lui manquent, dans un module du logiciel. '
            .'À utiliser pour expliquer pourquoi un bouton est grisé, verrouillé ou absent.';
    }

    public function handle(Request $request): Stringable|string
    {
        $module = (string) $request->string('module');

        if (! $this->knowledge->has($module)) {
            return 'Module inconnu. Modules possibles : '.implode(', ', $this->knowledge->moduleKeys()).'.';
        }

        $title = $this->knowledge->title($module);

        if (! $this->knowledge->accessible($module, $this->user)) {
            return "Ce compte ne peut pas ouvrir le module « {$title} ». Il doit demander à l’administrateur "
                .'le droit correspondant (Super Administration › Rôles & permissions).';
        }

        $granted = $this->context->grantedFor($this->user, $module);
        $prefixes = $this->knowledge->prefixes($module);

        $missing = $prefixes === [] ? collect() : Permission::query()
            ->orderBy('name')
            ->get(['name', 'label'])
            ->filter(fn (Permission $permission) => ! in_array($permission->name, $granted, true)
                && collect($prefixes)->contains(fn (string $prefix) => str_starts_with($permission->name, $prefix)))
            ->take(40)
            ->map(fn (Permission $permission) => $permission->name.($permission->label ? ' — '.$permission->label : ''));

        $lines = ["Module « {$title} » : ce compte peut l’ouvrir."];
        $lines[] = $granted === [] ? 'Droits détenus : aucun droit particulier.' : 'Droits détenus : '.implode(', ', $granted).'.';
        $lines[] = $missing->isEmpty()
            ? 'Aucun droit de ce module ne lui manque.'
            : "Droits qui lui manquent (à demander à l’administrateur) :\n- ".$missing->implode("\n- ");

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()
                ->description('La clé du module.')
                ->enum(app(AssistantKnowledge::class)->moduleKeys())
                ->required(),
        ];
    }
}
