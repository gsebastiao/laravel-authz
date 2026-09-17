<?php

namespace Gsebastiao\LaravelAuthz\Traits;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;

trait HasPermissions
{
    protected function authz(): Authorization
    {
        return app(Authorization::class);
    }

    public function hasPermission(int|string $permission): bool
    {
        return $this->authz()->hasPermission($permission, $this->id);
    }

    public function getEffectivePermissions(string $format = 'both'): array
    {
        $enum = match($format) {
            'id' => PermissionFormat::Id,
            'permission' => PermissionFormat::Permission,
            default => PermissionFormat::Both,
        };
        
        return $this->authz()->getEffectivePermissions($this->id, $enum);
    }

    public function getPermissionNames(): array
    {
        return $this->getEffectivePermissions('permission');
    }

    public function getPermissionIds(): array
    {
        return $this->getEffectivePermissions('id');
    }

    public function grantPermission(int $permissionId, array $options = []): bool
    {
        $this->authz()->grantPermissionToUser($this->id, $permissionId, $options);
        return true;
    }

    public function revokePermission(int $permissionId): bool
    {
        // Busca o overrideId
        $override = \Illuminate\Support\Facades\DB::table(
            config('authz.tables.permissions_users', 'auth_permissions_users')
        )
            ->where('user_id', $this->id)
            ->where('permission_id', $permissionId)
            ->whereNull('deleted_at')
            ->first();
            
        if (!$override) {
            return false;
        }
        
        $this->authz()->revokeUserPermission($override->id);
        return true;
    }

    public function syncPermissions(array $permissionIds): bool
    {
        $currentIds = $this->getPermissionIds();
        
        // Remove as que não estão na lista
        foreach ($currentIds as $id) {
            if (!in_array($id, $permissionIds)) {
                $this->revokePermission($id);
            }
        }
        
        // Adiciona as que não estão na lista atual
        foreach ($permissionIds as $id) {
            if (!in_array($id, $currentIds)) {
                $this->grantPermission($id);
            }
        }
        
        return true;
    }
}