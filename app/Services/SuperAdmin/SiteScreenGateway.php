<?php

namespace App\Services\SuperAdmin;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ADR-187 / ADR-189 — le passage du portail vers un espace d'un site (les RH,
 * la Pharmacie).
 *
 * Le portail n'a ni les données ni les règles du site : il transmet la requête
 * du Super Admin à l'API du site, avec son identité et ses droits, et le site
 * répond avec ses propres écrans. Aucun accès à la base du site (ADR-004,
 * ADR-027). Chaque espace dit seulement où il vit, sur le site et sur le
 * portail, et quels écrans le portail accepte d'afficher.
 */
abstract class SiteScreenGateway
{
    /** Le préfixe de l'API du site, après `/api/v1/` (`super-admin/hr`). */
    abstract public function apiPrefix(): string;

    /** Où l'espace vit sur le site (`/administration`). */
    abstract public function siteRoot(): string;

    /** Où l'espace vit sur le portail, sous `/super-admin/sites/{code}/` (`rh`). */
    abstract protected function portalSegment(): string;

    /**
     * Les écrans que le portail accepte d'afficher : un nom exact, ou un
     * dossier (`Pharmacy/`).
     *
     * @return array<int, string>
     */
    abstract protected function screens(): array;

    /** La page d'arrivée de l'espace sur le site, relative à sa racine. */
    public function landingPath(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function site(string $code): array
    {
        $site = collect(config('rivo.clinics', []))->firstWhere('code', mb_strtoupper($code));

        abort_unless($site, 404);

        return $site;
    }

    public function configured(array $site): bool
    {
        return filled($site['api_url'] ?? null) && filled($site['api_token'] ?? null);
    }

    /** L'adresse du portail où vit cet espace pour un site. */
    public function base(array $site): string
    {
        return '/super-admin/sites/'.$site['code'].'/'.$this->portalSegment();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $files
     *
     * @throws ConnectionException
     */
    public function forward(
        array $site,
        User $actor,
        string $method,
        string $path,
        array $query,
        array $input,
        array $files,
        ?string $refererPath,
    ): Response {
        $apiUrl = rtrim(trim((string) $site['api_url']), '/');
        $url = $apiUrl.'/'.$this->apiPrefix().($path !== '' ? '/'.ltrim($path, '/') : '');

        $pending = Http::acceptJson()
            ->withToken(trim((string) $site['api_token']))
            ->withHeaders([
                'X-Request-UUID' => (string) Str::uuid(),
                'X-Rivo-Actor-UUID' => $actor->uuid,
                'X-Rivo-Actor-Name' => $actor->name,
                'X-Rivo-Actor-Permissions' => $actor->effectivePermissionNames()->implode(','),
                // `back()` du site revient à la page d'où vient le Super Admin.
                'Referer' => $this->origin($apiUrl).$this->siteRoot().($refererPath ?? ''),
            ])
            // Un import ou un export dépasse volontiers les quelques
            // secondes d'une lecture ordinaire.
            ->timeout(max(15, (int) config('rivo.site_api.timeout', 5)));

        if ($method === 'GET') {
            return $pending
                ->retry(max(1, (int) config('rivo.site_api.retry_times', 2)), 150, throw: false)
                ->get($url, $query);
        }

        // Une écriture ne part qu'une fois : la clé d'idempotence protège un
        // double clic, jamais une nouvelle tentative silencieuse.
        $pending = $pending->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
        $url .= $query !== [] ? '?'.http_build_query($query) : '';

        if ($files === []) {
            return $pending->send($method, $url, ['json' => $input]);
        }

        foreach (Arr::dot($files) as $name => $file) {
            if ($file instanceof UploadedFile) {
                $pending = $pending->attach(
                    $this->fieldName($name),
                    (string) file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName(),
                );
            }
        }

        // En multipart, PUT et DELETE voyagent comme POST + `_method`.
        $fields = $this->multipartFields($input);

        if ($method !== 'POST') {
            $fields['_method'] = $method;
        }

        return $pending->post($url, $fields);
    }

    /** Seuls les écrans de l'espace s'affichent depuis le portail. */
    public function isScreen(mixed $component): bool
    {
        if (! is_string($component)) {
            return false;
        }

        return collect($this->screens())->contains(fn (string $screen) => str_ends_with($screen, '/')
            ? str_starts_with($component, $screen)
            : $component === $screen);
    }

    /**
     * Une adresse du site → la même adresse sur le portail.
     *
     * Les liens d'une page (pagination, téléchargement) pointent vers le site :
     * `…/administration/…` ou `…/api/v1/super-admin/hr/…`, par exemple. Tout
     * autre lien est laissé tel quel.
     */
    public function portalUrl(string $value, string $base): string
    {
        return (string) preg_replace(
            '#^(?:https?://[^/]+)?(?:/api/v1/'.preg_quote($this->apiPrefix(), '#').'|'.preg_quote($this->siteRoot(), '#').')(?=[/?\#]|$)#',
            $base,
            $value,
        );
    }

    /**
     * @param  array<mixed>  $props
     * @return array<mixed>
     */
    public function portalProps(array $props, string $base): array
    {
        array_walk_recursive($props, function (mixed &$value) use ($base): void {
            if (is_string($value) && str_contains($value, '/')) {
                $value = $this->portalUrl($value, $base);
            }
        });

        return $props;
    }

    /** Un chemin du portail → le même chemin sous la racine du site. */
    public function sitePathFrom(?string $portalUrl, string $base): ?string
    {
        if (! filled($portalUrl)) {
            return null;
        }

        $path = (string) (parse_url($portalUrl, PHP_URL_PATH) ?: '');
        $query = parse_url($portalUrl, PHP_URL_QUERY);

        if ($path !== $base && ! str_starts_with($path, $base.'/')) {
            return null;
        }

        return substr($path, strlen($base)).($query ? '?'.$query : '');
    }

    private function origin(string $apiUrl): string
    {
        $parts = parse_url($apiUrl);

        return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** `lines.0.label` → `lines[0][label]`. */
    private function fieldName(string $dotted): string
    {
        $segments = explode('.', $dotted);

        return array_shift($segments).implode('', array_map(fn (string $segment) => '['.$segment.']', $segments));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function multipartFields(array $input): array
    {
        $fields = [];

        foreach (Arr::dot($input) as $name => $value) {
            if ($value instanceof UploadedFile || is_array($value)) {
                continue;
            }

            $fields[$this->fieldName((string) $name)] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $fields;
    }
}
