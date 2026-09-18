<?php

/*
 * Funções globais de atalho. Todas usam o usuário logado quando
 * $userId não é informado, e retornam false para visitantes.
 *
 * Se outro pacote já tiver uma função com o mesmo nome, a dele é mantida
 * (function_exists). As diretivas Blade e o middleware deste pacote NÃO
 * dependem destas funções, então continuam funcionando nesse caso.
 */

use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Gsebastiao\LaravelAuthz\Models\Authorization;

if (!function_exists('authz')) {
    /**
     * authz()                        -> o serviço (Authorization)
     * authz('financeiro.aprovar')    -> bool, para o usuário logado
     * authz('financeiro.aprovar', 5) -> bool, para o usuário 5
     */
    function authz(?string $permission = null, ?int $userId = null): Authorization|bool
    {
        $service = app(Authorization::class);

        return $permission === null ? $service : $service->hasPermission($permission, $userId);
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission(int|string $permission, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasPermission($permission, $userId);
    }
}

if (!function_exists('hasAnyPermission')) {
    function hasAnyPermission(array $permissions, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasAnyPermission($permissions, $userId);
    }
}

if (!function_exists('hasAllPermissions')) {
    function hasAllPermissions(array $permissions, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasAllPermissions($permissions, $userId);
    }
}

if (!function_exists('hasRole')) {
    function hasRole(int|string $role, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasRole($role, $userId);
    }
}

if (!function_exists('hasAnyRole')) {
    function hasAnyRole(array $roles, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasAnyRole($roles, $userId);
    }
}

if (!function_exists('hasAllRoles')) {
    function hasAllRoles(array $roles, ?int $userId = null): bool
    {
        return app(Authorization::class)->hasAllRoles($roles, $userId);
    }
}

if (!function_exists('getUserGroups')) {
    function getUserGroups(?int $userId = null): array
    {
        return app(Authorization::class)->getUserGroups($userId);
    }
}

if (!function_exists('getUserPermissions')) {
    /** @param  'both'|'id'|'permission'  $format */
    function getUserPermissions(?int $userId = null, string $format = 'both'): array
    {
        return app(Authorization::class)->getEffectivePermissions(
            $userId,
            PermissionFormat::tryFrom($format) ?? PermissionFormat::Both
        );
    }
}
