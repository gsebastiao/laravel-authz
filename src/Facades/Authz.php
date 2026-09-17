<?php
// src/Facades/Authz.php

namespace Gsebastiao\LaravelAuthz\Facades;

use Illuminate\Support\Facades\Facade;
use Gsebastiao\LaravelAuthz\Models\Authorization;

/**
 * @method static bool hasPermission(int|string $permission, ?int $userId = null)
 * @method static array getEffectivePermissions(?int $userId = null, \Gsebastiao\LaravelAuthz\Enums\PermissionFormat $format = PermissionFormat::Both)
 * @method static array getUserGroups(?int $userId = null)
 * @method static int createGroup(string $name, ?string $description = null, int $status = 1)
 * @method static bool updateGroup(int $id, array $data)
 * @method static bool deleteGroup(int $id, bool $purge = false)
 * @method static int addUserToGroup(int $userId, int $groupId, array $options = [])
 * @method static bool updateGroupMembership(int $membershipId, array $data)
 * @method static bool removeUserFromGroup(int $membershipId, bool $purge = false)
 * @method static int createPermission(string $permission, string $module, string $action, string $label, ?string $description = null, ?int $tenantId = null)
 * @method static bool updatePermission(int $id, array $data)
 * @method static bool deletePermission(int $id, bool $purge = false)
 * @method static int grantPermissionToGroup(int $groupId, int $permissionId, array $options = [])
 * @method static bool updateGroupPermission(int $grantId, array $data)
 * @method static bool revokeGroupPermission(int $grantId, bool $purge = false)
 * @method static int grantPermissionToUser(int $userId, int $permissionId, array $options = [])
 * @method static bool updateUserPermission(int $overrideId, array $data)
 * @method static bool revokeUserPermission(int $overrideId, bool $purge = false)
 * @method static array getAuditTrail(string $tableKey, int|string $subjectId)
 * @method static void forgetUserCache(int $userId)
 * @method static \Illuminate\Database\Query\Builder applyAuditJoins(string $tableKey, ?\Illuminate\Database\Query\Builder $query = null, ?array $events = null)
 * @method static \Illuminate\Database\Eloquent\Builder applyWhereHasPermission(\Illuminate\Database\Eloquent\Builder $query, string $permission, string $userIdColumn = 'id')
 * @method static array getAssignablePermissions(\Gsebastiao\LaravelAuthz\Enums\PermissionFormat $format = PermissionFormat::Both)
 * @method static void registerGates()
 */
class Authz extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Authorization::class;
    }
}