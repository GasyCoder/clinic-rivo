<?php

namespace Database\Seeders\Concerns;

use LogicException;

/**
 * Development data never reaches production.
 *
 * Reads APP_ENV as well as the running environment name: the local portal is
 * started with `--env=admin`, which renames the environment to "admin" even
 * though its .env.admin declares APP_ENV=local. Production declares
 * APP_ENV=production and is refused either way.
 */
trait LocalOnly
{
    protected static function isLocalEnvironment(): bool
    {
        return app()->environment('local', 'testing')
            || in_array(config('app.env'), ['local', 'testing'], true);
    }

    protected function ensureLocal(): void
    {
        if (! self::isLocalEnvironment()) {
            throw new LogicException(static::class.' est strictement interdit hors des environnements local et testing.');
        }
    }
}
