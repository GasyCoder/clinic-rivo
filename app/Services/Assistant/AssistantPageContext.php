<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * ADR-222 — ce que l'assistant sait de l'endroit où l'utilisateur se trouve.
 *
 * Rien de la page elle-même ne part au fournisseur : ni ses données, ni son titre
 * (il peut porter le nom d'un patient). Seulement :
 *
 *   - l'adresse, identifiants remplacés par {id} ;
 *   - le nom de l'écran (composant Inertia) et la section ouverte (#ordonnance) ;
 *   - le rôle, le profil métier et le site du compte ;
 *   - les modules qu'il peut ouvrir, et ses droits sur le module de la page.
 *
 * L'adresse et l'écran viennent du navigateur : ils sont revalidés ici, et un
 * compte ne reçoit que l'aide des modules qu'il peut réellement ouvrir.
 */
class AssistantPageContext
{
    public function __construct(private readonly AssistantKnowledge $knowledge) {}

    /**
     * @param  array{path?: ?string, component?: ?string, section?: ?string}  $page
     * @return array{path: ?string, component: ?string, section: ?string, module: ?string}
     */
    public function normalize(array $page): array
    {
        $path = $this->sanitizePath($page['path'] ?? null);
        $component = $this->matches($page['component'] ?? null, '/^[A-Za-z0-9\/_\-]{1,120}$/');
        $section = $this->matches(ltrim((string) ($page['section'] ?? ''), '#'), '/^[A-Za-z0-9_\-]{1,60}$/');

        return [
            'path' => $path,
            'component' => $component,
            'section' => $section,
            'module' => $this->knowledge->moduleForPath($path),
        ];
    }

    /**
     * Le bloc de contexte joint aux consignes de l'agent.
     *
     * @param  array{path: ?string, component: ?string, section: ?string, module: ?string}  $page
     */
    public function describe(User $user, array $page): string
    {
        $user->loadMissing(['role', 'professionalProfile']);

        $lines = [];
        $role = $user->role;
        $profile = $user->professionalProfile;

        $lines[] = 'Rôle du compte : '.($role ? '« '.$role->name.' » ('.$role->code.')' : 'non renseigné').
            ($profile ? ', profil métier « '.$profile->name.' »' : '').'.';

        $lines[] = config('rivo.site.type') === 'admin'
            ? 'Déploiement : portail Super Administration (admin.rivo.mg) — il règle les sites par leur API.'
            : 'Déploiement : site clinique « '.config('rivo.site.name').' » ('.config('rivo.site.code').').';

        if ($page['path'] !== null) {
            $where = 'Page ouverte : '.$page['path'];
            $where .= $page['component'] ? ' — écran '.$page['component'] : '';
            $where .= $page['section'] ? ', section « '.$page['section'].' »' : '';
            $lines[] = $where.'.';
        }

        $module = $page['module'];

        if ($module !== null && $this->knowledge->accessible($module, $user)) {
            $lines[] = 'Module de la page : '.$this->knowledge->title($module).'.';

            $granted = $this->grantedFor($user, $module);
            $lines[] = $granted === []
                ? 'Droits du compte sur ce module : aucun droit particulier en dehors de la consultation.'
                : 'Droits du compte sur ce module : '.implode(', ', $granted).'.';
        } elseif ($module !== null) {
            $lines[] = 'La page appartient au module « '.$this->knowledge->title($module).' », que ce compte ne peut pas ouvrir.';
        }

        $accessible = collect($this->knowledge->accessibleModules($user))
            ->reject(fn (string $key) => $key === 'general')
            ->map(fn (string $key) => $this->knowledge->title($key))
            ->implode(', ');

        $lines[] = 'Modules que ce compte peut ouvrir : '.($accessible !== '' ? $accessible : 'aucun module métier').'.';

        return implode("\n", $lines);
    }

    /**
     * Les droits que le compte détient réellement sur un module (résolution DENY >
     * ALLOW > socle, par la même méthode que le reste de l'application).
     *
     * @return list<string>
     */
    public function grantedFor(User $user, string $module): array
    {
        $prefixes = $this->knowledge->prefixes($module);

        if ($prefixes === []) {
            return [];
        }

        return collect($user->effectivePermissionNames())
            ->filter(fn (string $name) => collect($prefixes)->contains(fn (string $prefix) => str_starts_with($name, $prefix)))
            ->sort()
            ->values()
            ->take(40)
            ->all();
    }

    /** L'adresse sans requête ni fragment, identifiants remplacés : aucune donnée n'y reste. */
    private function sanitizePath(?string $path): ?string
    {
        $path = (string) parse_url('/'.ltrim((string) $path, '/'), PHP_URL_PATH);

        if ($path === '' || mb_strlen($path) > 255 || ! preg_match('#^/[A-Za-z0-9/_\-.]*$#', $path)) {
            return null;
        }

        $segments = array_map(function (string $segment): string {
            return preg_match('/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9]+|[0-9A-Za-z]{20,})$/i', $segment)
                ? '{id}'
                : $segment;
        }, explode('/', $path));

        return implode('/', $segments);
    }

    private function matches(?string $value, string $pattern): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match($pattern, $value) ? $value : null;
    }
}
