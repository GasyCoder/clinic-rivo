<?php

namespace App\Services\Gateway;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ADR-184 (amendement du 2026-10-02) — les logos de la passerelle (rivo.mg).
 *
 * Le logo de chaque site et le logo central (celui du portail) sont réglés
 * dans les Paramètres de chaque déploiement, donc dans sa base. La passerelle
 * n'y accède jamais (ADR-004) : elle lit l'identité publique de chacun
 * (`/branding/identity`), en même temps, avec un délai court, et la garde un
 * moment. Un déploiement qui ne répond pas est dit « indisponible », jamais
 * remplacé par un logo deviné.
 */
class GatewayBranding
{
    /** Une identité lue reste valable dix minutes ; un échec, une minute. */
    private const FRESH_SECONDS = 600;

    private const FAILED_SECONDS = 60;

    private const TIMEOUT_SECONDS = 3;

    /**
     * @return array{central: array<string, mixed>|null, clinics: array<string, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $targets = collect(config('rivo.clinics', []))
            ->filter(fn (array $clinic) => filled($clinic['url'] ?? null))
            ->mapWithKeys(fn (array $clinic) => [$clinic['code'] => $clinic['url']])
            ->all();

        $adminUrl = config('rivo.admin_url');

        if (filled($adminUrl)) {
            $targets['__central'] = $adminUrl;
        }

        $identities = $this->identities($targets);
        $central = $identities['__central'] ?? null;
        unset($identities['__central']);

        return [
            'central' => $central !== null && $central['status'] !== 'unavailable' ? $central : null,
            'clinics' => $identities,
        ];
    }

    /**
     * @param  array<string, string>  $targets  clé → adresse du déploiement
     * @return array<string, array<string, mixed>>
     */
    private function identities(array $targets): array
    {
        $found = [];
        $missing = [];

        foreach ($targets as $key => $url) {
            $cached = Cache::get($this->cacheKey($url));

            if (is_array($cached)) {
                $found[$key] = $cached;
            } else {
                $missing[$key] = $url;
            }
        }

        if ($missing === []) {
            return $found;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => collect($missing)
                ->map(fn (string $url, string $key) => $pool->as($key)
                    ->acceptJson()
                    ->connectTimeout(2)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get(rtrim($url, '/').'/branding/identity'))
                ->all());
        } catch (Throwable) {
            $responses = [];
        }

        foreach ($missing as $key => $url) {
            $response = $responses[$key] ?? null;
            $identity = $response instanceof Response ? $this->normalize($response, $url) : null;
            $identity ??= ['status' => 'unavailable', 'brand' => null, 'logo_url' => null, 'icon_url' => null, 'custom_logo' => false];

            Cache::put(
                $this->cacheKey($url),
                $identity,
                $identity['status'] === 'unavailable' ? self::FAILED_SECONDS : self::FRESH_SECONDS,
            );
            $found[$key] = $identity;
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function normalize(Response $response, string $url): ?array
    {
        $data = $response->successful() ? $response->json() : null;

        // Un site pas encore déployé répond par une page HTML : ce n'est pas RIVO.
        if (! is_array($data) || ! is_string($data['brand'] ?? null)) {
            return null;
        }

        return [
            'status' => ($data['maintenance'] ?? false) === true ? 'maintenance' : 'online',
            'brand' => mb_substr($data['brand'], 0, 120),
            'logo_url' => $this->sameHost($data['logo_url'] ?? null, $url),
            'custom_logo' => ($data['custom_logo'] ?? false) === true,
            'icon_url' => $this->sameHost($data['icon_url'] ?? null, $url),
        ];
    }

    /**
     * Seule une image servie par le déploiement lui-même est retenue : la
     * passerelle n'affiche jamais une adresse qu'il ne sert pas.
     */
    private function sameHost(mixed $value, string $url): ?string
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return $host !== null && strcasecmp($host, (string) parse_url($url, PHP_URL_HOST)) === 0 ? $value : null;
    }

    private function cacheKey(string $url): string
    {
        return 'gateway:identity:'.sha1(rtrim($url, '/'));
    }
}
