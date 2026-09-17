<?php

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Illuminate\Support\Facades\Auth;

if (!function_exists('authz')) {
    /**
     * Retorna a instância do Authorization ou verifica permissão.
     * 
     * @param string|null $permission
     * @param int|null $userId
     * @return Authorization|bool
     * 
     * @example
     *   // Instância do Authorization
     *   authz()->getUserGroups($userId);
     * 
     *   // Verificar permissão
     *   authz('financeiro.aprovar'); // bool
     *   authz('financeiro.aprovar', $userId); // bool
     */
    function authz(?string $permission = null, ?int $userId = null): Authorization|bool
    {
        $auth = app(Authorization::class);
        
        if ($permission === null) {
            return $auth;
        }
        
        return $auth->hasPermission($permission, $userId ?? Auth::id());
    }
}

if (!function_exists('hasPermission')) {
    /**
     * Verifica se o usuário atual (ou especificado) tem uma permissão.
     * 
     * @param string $permission
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasPermission('financeiro.aprovar')) {
     *       // ...
     *   }
     */
    function hasPermission(string $permission, ?int $userId = null): bool
    {
        // Verificar se $userId é válido
        if ($userId !== null && $userId <= 0) {
            return false;
        }

        return authz($permission, $userId);
    }
}

if (!function_exists('hasAnyPermission')) {
    /**
     * Verifica se o usuário tem alguma das permissões listadas.
     * 
     * @param array $permissions
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasAnyPermission(['financeiro.aprovar', 'financeiro.ver'])) {
     *       // ...
     *   }
     */
    function hasAnyPermission(array $permissions, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return false;
        }
        
        $auth = authz();
        
        foreach ($permissions as $permission) {
            if ($auth->hasPermission($permission, $userId)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('hasAllPermissions')) {
    /**
     * Verifica se o usuário tem TODAS as permissões listadas.
     * 
     * @param array $permissions
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasAllPermissions(['financeiro.aprovar', 'financeiro.ver'])) {
     *       // ...
     *   }
     */
    function hasAllPermissions(array $permissions, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return false;
        }
        
        $auth = authz();
        
        foreach ($permissions as $permission) {
            if (!$auth->hasPermission($permission, $userId)) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('hasRole')) {
    /**
     * Verifica se o usuário tem um papel (grupo).
     * 
     * @param string $role
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasRole('admin')) {
     *       // ...
     *   }
     */
    function hasRole(string $role, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return false;
        }
        
        $groups = authz()->getUserGroups($userId);
        
        return collect($groups)->contains('name', $role);
    }
}

if (!function_exists('hasAnyRole')) {
    /**
     * Verifica se o usuário tem algum dos papéis listados.
     * 
     * @param array $roles
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasAnyRole(['admin', 'manager'])) {
     *       // ...
     *   }
     */
    function hasAnyRole(array $roles, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return false;
        }
        
        $groups = authz()->getUserGroups($userId);
        $groupNames = collect($groups)->pluck('name')->toArray();
        
        foreach ($roles as $role) {
            if (in_array($role, $groupNames)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('hasAllRoles')) {
    /**
     * Verifica se o usuário tem TODOS os papéis listados.
     * 
     * @param array $roles
     * @param int|null $userId
     * @return bool
     * 
     * @example
     *   if (hasAllRoles(['admin', 'financeiro'])) {
     *       // ...
     *   }
     */
    function hasAllRoles(array $roles, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return false;
        }
        
        $groups = authz()->getUserGroups($userId);
        $groupNames = collect($groups)->pluck('name')->toArray();
        
        foreach ($roles as $role) {
            if (!in_array($role, $groupNames)) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('getUserGroups')) {
    /**
     * Retorna os grupos do usuário.
     * 
     * @param int|null $userId
     * @return array
     * 
     * @example
     *   $groups = getUserGroups();
     *   $groups = getUserGroups($userId);
     */
    function getUserGroups(?int $userId = null): array
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return [];
        }
        
        return authz()->getUserGroups($userId);
    }
}

if (!function_exists('getUserPermissions')) {
    /**
     * Retorna as permissões do usuário.
     * 
     * @param int|null $userId
     * @param string $format 'both'|'id'|'permission'
     * @return array
     * 
     * @example
     *   $permissions = getUserPermissions(); // ['financeiro.aprovar', ...]
     *   $ids = getUserPermissions(null, 'id'); // [1, 3, 5]
     */
    function getUserPermissions(?int $userId = null, string $format = 'both'): array
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            return [];
        }
        
        $enum = match($format) {
            'id' => PermissionFormat::Id,
            'permission' => PermissionFormat::Permission,
            default => PermissionFormat::Both,
        };
        
        return authz()->getEffectivePermissions($userId, $enum);
    }
}

// can()/cannot() foram removidos deliberadamente: são nomes muito
// comuns, com risco real de colisão com o que o projeto host já
// define (inclusive o próprio can()/cannot() built-in do Laravel via
// AuthorizesRequests). hasPermission() cobre o mesmo caso de uso sem
// esse risco.