<?php

namespace App\Services\Authorization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Quelles permissions l'application vérifie réellement quelque part.
 *
 * Une permission n'est pas un réglage : c'est un mot que le code doit
 * connaître. En créer une depuis un écran est légitime — préparer le droit
 * avant la fonctionnalité, ou nommer un besoin métier — mais une permission
 * que rien ne vérifie n'ouvre et ne ferme rien. Affichée comme les autres,
 * elle se lit comme une protection qui n'existe pas.
 *
 * Plutôt que d'interdire la création, on dit la vérité : ce service répond
 * « ce nom est-il vérifié ? » à partir du code lui-même, jamais d'une liste
 * tenue à la main qui divergerait au premier oubli.
 *
 * Trois sources, parce qu'une permission peut protéger trois choses :
 *
 *   la route     `->middleware('can:patients.view')`
 *   le serveur   `$actor->cannot('roles.create')`, une Policy, une Action
 *   l'interface  `can('stock.view')` dans un écran ou le menu latéral
 *
 * Le balayage ne lit pas les mêmes choses des deux côtés, et c'est
 * délibéré. Côté PHP, toute chaîne citée ayant la forme d'un nom compte :
 * `app/` et `routes/` ne contiennent pas de texte d'exemple, et une Policy
 * peut citer un droit de bien des façons. Côté interface, en revanche, seuls
 * les emplacements qui *vérifient* réellement un droit comptent —
 * `can('…')` et les `permission:` du menu et des espaces de travail. Sans
 * cette distinction, l'exemple affiché dans le champ « Nom » de cet écran
 * suffisait à faire passer une permission pour vérifiée (constaté en test).
 */
class PermissionUsageScanner
{
    public const CACHE_KEY = 'rivo.permissions.used_names';

    /** Assez court pour suivre le développement, assez long pour ne pas relire à chaque clic. */
    private const TTL_SECONDS = 300;

    /** @var array<int, string> */
    private const ROOTS = ['app', 'routes', 'resources/js'];

    /** @var array<int, string> */
    private const EXTENSIONS = ['php', 'vue', 'js'];

    /**
     * Les noms que le code vérifie quelque part.
     *
     * @return array<string, true>
     */
    public function usedNames(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
            $names = $this->fromRoutes();

            foreach ($this->sourceFiles() as $path => $extension) {
                $contents = File::get($path);
                $found = $extension === 'php'
                    ? $this->quotedNames($contents)
                    : $this->checkedNamesInUi($contents);

                foreach ($found as $name) {
                    $names[$name] = true;
                }
            }

            return $names;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Le `can:` des routes, lu sur la table réelle plutôt que sur le texte
     * des fichiers : une route enregistrée par un package ou une macro
     * compte autant que celles écrites à la main.
     *
     * @return array<string, true>
     */
    private function fromRoutes(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                // `can:permission,model` — seule la capacité nous intéresse.
                $ability = explode(',', mb_substr($middleware, 4))[0];

                if ($this->looksLikeName($ability)) {
                    $names[$ability] = true;
                }
            }
        }

        return $names;
    }

    /** @return \Generator<string, string> chemin => extension */
    private function sourceFiles(): \Generator
    {
        foreach (self::ROOTS as $root) {
            $directory = base_path($root);

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if (in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    yield $file->getPathname() => $file->getExtension();
                }
            }
        }
    }

    /**
     * Côté interface, un nom ne compte que s'il est réellement interrogé :
     * `can('stock.view')`, ou le `permission:` d'une entrée de menu ou d'un
     * espace de travail. Un nom cité ailleurs — un exemple, un texte d'aide —
     * ne protège rien et ne doit pas se faire passer pour un contrôle.
     *
     * @return array<int, string>
     */
    private function checkedNamesInUi(string $source): array
    {
        $names = [];

        preg_match_all(
            '/\b(?:can|cannot|hasPermission|hasPermissionTo)\(\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/',
            $source,
            $calls,
        );
        $names = array_merge($names, $calls[1]);

        preg_match_all('/permissions?\s*:\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $source, $declarations);

        foreach ($declarations[1] as $declaration) {
            $names = array_merge($names, $this->quotedNames($declaration));
        }

        return array_unique($names);
    }

    /** @return array<int, string> */
    private function quotedNames(string $source): array
    {
        preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/', $source, $matches);

        return array_unique($matches[1]);
    }

    private function looksLikeName(string $value): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $value);
    }
}
