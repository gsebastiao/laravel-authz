<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

use Gsebastiao\LaravelAuthz\Models\Authorization;

/**
 * Base comum de HasRoles e HasPermissions. Existe para que os dois
 * traits possam ser usados juntos no mesmo model sem conflito.
 */
trait InteractsWithAuthz
{
    protected function authz(): Authorization
    {
        return app(Authorization::class);
    }

    protected function authzUserId(): int
    {
        return (int) $this->getKey();
    }

    protected static function authzTable(string $key): string
    {
        return config("authz.tables.{$key}");
    }
}
