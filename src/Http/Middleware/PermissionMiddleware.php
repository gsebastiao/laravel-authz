<?php

namespace Gsebastiao\LaravelAuthz\Http\Middleware;

use Closure;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Protege rotas por permissão.
 *
 *   ->middleware('authz.permission:financeiro.aprovar')             precisa desta
 *   ->middleware('authz.permission:financeiro.ver|financeiro.editar') precisa de UMA delas
 *   ->middleware('authz.permission:financeiro.ver,relatorio.ver')     precisa de TODAS
 *
 * Visitante não logado: vai para o login (401 em requisições JSON).
 * Logado sem permissão: erro 403.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        if (!$permissions) {
            throw new \InvalidArgumentException("Informe a permissão: ->middleware('authz.permission:nome.da.permissao').");
        }

        if (!$request->user()) {
            throw new AuthenticationException();
        }

        $authz = app(Authorization::class);
        $userId = (int) $request->user()->getAuthIdentifier();

        foreach ($permissions as $anyOf) {
            if (!$this->check($authz, explode('|', $anyOf), $userId)) {
                abort(403, 'Você não tem permissão para acessar esta página.');
            }
        }

        return $next($request);
    }

    protected function check(Authorization $authz, array $names, int $userId): bool
    {
        return $authz->hasAnyPermission(array_map('trim', $names), $userId);
    }
}
