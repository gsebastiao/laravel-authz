<?php

namespace Gsebastiao\LaravelAuthz\Http\Middleware;

use Gsebastiao\LaravelAuthz\Models\Authorization;

/**
 * Protege rotas por grupo. Mesma sintaxe do authz.permission:
 *
 *   ->middleware('authz.role:financeiro')         precisa estar neste grupo
 *   ->middleware('authz.role:financeiro|diretor') em UM deles
 *   ->middleware('authz.role:financeiro,diretor') em TODOS
 */
class RoleMiddleware extends PermissionMiddleware
{
    protected function check(Authorization $authz, array $names, int $userId): bool
    {
        return $authz->hasAnyRole(array_map('trim', $names), $userId);
    }
}
