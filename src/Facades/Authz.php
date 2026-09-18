<?php

namespace Gsebastiao\LaravelAuthz\Facades;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Support\Facades\Facade;

/**
 * Atalho estático para o serviço Authorization. Ex: Authz::hasPermission('financeiro.aprovar')
 *
 * Checagens (usuário logado quando $userId = null)
 * @method static bool hasPermission(int|string $permission, ?int $userId = null)
 * @method static bool hasAnyPermission(array $permissions, ?int $userId = null)
 * @method static bool hasAllPermissions(array $permissions, ?int $userId = null)
 * @method static bool hasRole(int|string $role, ?int $userId = null)
 * @method static bool hasAnyRole(array $roles, ?int $userId = null)
 * @method static bool hasAllRoles(array $roles, ?int $userId = null)
 * @method static array getEffectivePermissions(?int $userId = null, \Gsebastiao\LaravelAuthz\Enums\PermissionFormat $format = \Gsebastiao\LaravelAuthz\Enums\PermissionFormat::Both)
 * @method static array getUserGroups(?int $userId = null)
 * @method static array getAssignablePermissions(\Gsebastiao\LaravelAuthz\Enums\PermissionFormat $format = \Gsebastiao\LaravelAuthz\Enums\PermissionFormat::Both)
 *
 * Grupos e membros
 * @method static int createGroup(string $name, ?string $description = null, int $status = 1)
 * @method static bool updateGroup(int $id, array $data)
 * @method static bool deleteGroup(int $id, bool $purge = false)
 * @method static bool restoreGroup(int $id)
 * @method static int addUserToGroup(int $userId, int $groupId, array $options = [])
 * @method static bool updateGroupMembership(int $membershipId, array $data)
 * @method static bool removeUserFromGroup(int $membershipId, bool $purge = false)
 *
 * Permissões e regras
 * @method static int createPermission(string $permission, string $module, string $action, string $label, ?string $description = null, ?int $tenantId = null)
 * @method static bool updatePermission(int $id, array $data)
 * @method static bool deletePermission(int $id, bool $purge = false)
 * @method static bool restorePermission(int $id)
 * @method static int grantPermissionToGroup(int $groupId, int $permissionId, array $options = [])
 * @method static bool updateGroupPermission(int $grantId, array $data)
 * @method static bool revokeGroupPermission(int $grantId, bool $purge = false)
 * @method static int grantPermissionToUser(int $userId, int $permissionId, array $options = [])
 * @method static bool updateUserPermission(int $overrideId, array $data)
 * @method static bool revokeUserPermission(int $overrideId, bool $purge = false)
 * @method static int resolveGroupId(int|string $group)
 * @method static int resolvePermissionId(int|string $permission)
 *
 * Cache, auditoria e integração
 * @method static void forgetUserCache(int $userId)
 * @method static void flushCache()
 * @method static array getAuditTrail(string $tableKey, int|string $subjectId)
 * @method static \Illuminate\Database\Query\Builder applyAuditJoins(string $tableKey, ?\Illuminate\Database\Query\Builder $query = null, ?array $events = null)
 * @method static \Illuminate\Database\Eloquent\Builder applyWhereHasPermission(\Illuminate\Database\Eloquent\Builder $query, string $permission, string $userIdColumn = 'id')
 * @method static void registerGates(?\Illuminate\Contracts\Auth\Access\Gate $gate = null)
 *
 * @see Authorization
 */
class Authz extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Authorization::class;
    }
}
