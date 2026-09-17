<?php

namespace Gsebastiao\LaravelAuthz\Traits;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Illuminate\Support\Collection;

/**
 * Trait para gerenciar papéis (grupos) e permissões em modelos de usuário.
 * 
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasRoles
{
    /**
     * Instância do Authorization (singleton)
     */
    protected function authz(): Authorization
    {
        return app(Authorization::class);
    }

    /**
     * Verifica se o usuário tem um papel específico (por nome ou ID)
     */
    public function hasRole(int|string $role): bool
    {
        $groups = $this->getGroups();
        
        if (is_int($role)) {
            return collect($groups)->contains('id', $role);
        }
        
        return collect($groups)->contains('name', $role);
    }

    /**
     * Verifica se o usuário tem algum dos papéis especificados
     */
    public function hasAnyRole(array $roles): bool
    {
        $groupIds = collect($this->getGroups())->pluck('id')->toArray();
        $groupNames = collect($this->getGroups())->pluck('name')->toArray();
        
        foreach ($roles as $role) {
            if (is_int($role) && in_array($role, $groupIds)) {
                return true;
            }
            if (is_string($role) && in_array($role, $groupNames)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verifica se o usuário tem todos os papéis especificados
     */
    public function hasAllRoles(array $roles): bool
    {
        $groupIds = collect($this->getGroups())->pluck('id')->toArray();
        $groupNames = collect($this->getGroups())->pluck('name')->toArray();
        
        foreach ($roles as $role) {
            if (is_int($role) && !in_array($role, $groupIds)) {
                return false;
            }
            if (is_string($role) && !in_array($role, $groupNames)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Retorna todos os papéis (grupos) do usuário
     */
    public function getGroups(): array
    {
        return $this->authz()->getUserGroups($this->id);
    }

    /**
     * Retorna os nomes dos papéis do usuário
     */
    public function getRoleNames(): array
    {
        return collect($this->getGroups())
            ->pluck('name')
            ->toArray();
    }

    /**
     * Retorna os IDs dos papéis do usuário
     */
    public function getRoleIds(): array
    {
        return collect($this->getGroups())
            ->pluck('id')
            ->toArray();
    }

    /**
     * Atribui um papel (grupo) ao usuário
     */
    public function assignRole(int|string $role, array $options = []): bool
    {
        if (is_string($role)) {
            $role = $this->findRoleIdByName($role);
            if (!$role) {
                throw new \InvalidArgumentException("Role '{$role}' not found");
            }
        }
        
        $this->authz()->addUserToGroup($this->id, $role, $options);
        return true;
    }

    /**
     * Atribui múltiplos papéis ao usuário
     */
    public function assignRoles(array $roles, array $options = []): bool
    {
        foreach ($roles as $role) {
            $this->assignRole($role, $options);
        }
        return true;
    }

    /**
     * Remove um papel (grupo) do usuário
     */
    public function removeRole(int|string $role): bool
    {
        $memberships = $this->getMemberships();
        
        if (is_string($role)) {
            $roleId = $this->findRoleIdByName($role);
            if (!$roleId) {
                return false;
            }
            $role = $roleId;
        }
        
        $membership = collect($memberships)
            ->firstWhere('group_id', $role);
            
        if (!$membership) {
            return false;
        }
        
        $this->authz()->removeUserFromGroup($membership['id']);
        return true;
    }

    /**
     * Sincroniza os papéis do usuário (remove os que não estão na lista)
     */
    public function syncRoles(array $roles): bool
    {
        $currentRoleIds = $this->getRoleIds();
        $targetRoleIds = [];
        
        foreach ($roles as $role) {
            if (is_string($role)) {
                $roleId = $this->findRoleIdByName($role);
                if ($roleId) {
                    $targetRoleIds[] = $roleId;
                }
            } else {
                $targetRoleIds[] = $role;
            }
        }
        
        // Remove papéis que não estão na lista alvo
        foreach ($currentRoleIds as $roleId) {
            if (!in_array($roleId, $targetRoleIds)) {
                $this->removeRole($roleId);
            }
        }
        
        // Adiciona papéis que não estão na lista atual
        foreach ($targetRoleIds as $roleId) {
            if (!in_array($roleId, $currentRoleIds)) {
                $this->assignRole($roleId);
            }
        }
        
        return true;
    }

    /**
     * Retorna todas as membresias (com detalhes) do usuário
     */
    public function getMemberships(): array
    {
        return \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups_users', 'auth_groups_users')
        )
            ->where('user_id', $this->id)
            ->whereNull('deleted_at')
            ->get()->toArray();
    }

    /**
     * Verifica se o usuário tem um papel com permissão específica
     */
    public function hasRoleWithPermission(string $permission): bool
    {
        $groups = $this->getGroups();
        $permissions = $this->authz()->getEffectivePermissions($this->id, PermissionFormat::Permission);
        
        if (!in_array($permission, $permissions)) {
            return false;
        }
        
        // Verifica se pelo menos um dos grupos do usuário tem essa permissão
        $groupIds = collect($groups)->pluck('id')->toArray();
        $groupPermissions = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.permissions_groups', 'auth_permissions_groups') . ' as pg'
        )
            ->join(
                config('authz.tables.permissions', 'auth_permissions') . ' as p',
                'pg.permission_id',
                '=',
                'p.id'
            )
            ->whereIn('pg.group_id', $groupIds)
            ->where('p.permission', $permission)
            ->whereNull('pg.deleted_at')
            ->where('pg.is_granted', true)
            ->exists();
            
        return $groupPermissions;
    }

    /**
     * Busca o ID de um papel pelo nome
     */
    protected function findRoleIdByName(string $name): ?int
    {
        $group = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups', 'auth_groups')
        )
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->first();
            
        return $group?->id;
    }

    /**
     * Retorna todos os usuários com um papel específico
     */
    public static function getUsersWithRole(int|string $role): Collection
    {
        if (is_string($role)) {
            $groupId = \Illuminate\Support\Facades\DB::table(
                config('authz.tables.groups', 'auth_groups')
            )
                ->where('name', $role)
                ->whereNull('deleted_at')
                ->value('id');
                
            if (!$groupId) {
                return collect();
            }
            $role = $groupId;
        }
        
        $users = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups_users', 'auth_groups_users')
        )
            ->where('group_id', $role)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->pluck('user_id');
            
        $userModel = config('authz.user_model') ?? config('auth.providers.users.model', 'App\\Models\\User');
        return $userModel::whereIn('id', $users)->get();
    }

    /**
     * Define o papel principal do usuário
     */
    public function setPrimaryRole(int|string $role): bool
    {
        // Primeiro, remove primary de todos
        \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups_users', 'auth_groups_users')
        )
            ->where('user_id', $this->id)
            ->update(['is_primary' => 0]);
            
        // Depois, define o novo primary
        if (is_string($role)) {
            $roleId = $this->findRoleIdByName($role);
            if (!$roleId) {
                throw new \InvalidArgumentException("Role '{$role}' not found");
            }
            $role = $roleId;
        }
        
        $updated = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups_users', 'auth_groups_users')
        )
            ->where('user_id', $this->id)
            ->where('group_id', $role)
            ->update(['is_primary' => 1]);
            
        if ($updated) {
            Authorization::forgetUserCache($this->id);
        }
        
        return (bool) $updated;
    }

    /**
     * Retorna o papel principal do usuário
     */
    public function getPrimaryRole(): ?array
    {
        $membership = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.groups_users', 'auth_groups_users') . ' as gu'
        )
            ->join(
                config('authz.tables.groups', 'auth_groups') . ' as g',
                'gu.group_id',
                '=',
                'g.id'
            )
            ->where('gu.user_id', $this->id)
            ->where('gu.is_primary', 1)
            ->whereNull('gu.deleted_at')
            ->whereNull('g.deleted_at')
            ->first(['g.id', 'g.name', 'g.description']);
            
        return $membership ? (array) $membership : null;
    }

    /**
     * Verifica se o usuário está em um grupo (papel)
     * Alias para hasRole
     */
    public function isInGroup(int|string $group): bool
    {
        return $this->hasRole($group);
    }

    /**
     * Retorna os grupos (papéis) formatados para select
     */
    public function getGroupsForSelect(): array
    {
        return collect($this->getGroups())
            ->pluck('name', 'id')
            ->toArray();
    }
}