<?php

namespace Gsebastiao\LaravelAuthz\Models;

use Gsebastiao\LaravelAuthz\Concerns\AuditsCrud;
use Gsebastiao\LaravelAuthz\Concerns\Cacheable;
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Gsebastiao\LaravelAuthz\Events\GroupCreated;
use Gsebastiao\LaravelAuthz\Events\GroupDeleted;
use Gsebastiao\LaravelAuthz\Events\GroupMembershipUpdated;
use Gsebastiao\LaravelAuthz\Events\GroupUpdated;
use Gsebastiao\LaravelAuthz\Events\PermissionCreated;
use Gsebastiao\LaravelAuthz\Events\PermissionDeleted;
use Gsebastiao\LaravelAuthz\Events\PermissionGrantedToGroup;
use Gsebastiao\LaravelAuthz\Events\PermissionGrantedToUser;
use Gsebastiao\LaravelAuthz\Events\PermissionGrantToGroupRejected;
use Gsebastiao\LaravelAuthz\Events\PermissionRevokedFromGroup;
use Gsebastiao\LaravelAuthz\Events\PermissionRevokedFromUser;
use Gsebastiao\LaravelAuthz\Events\PermissionUpdated;
use Gsebastiao\LaravelAuthz\Events\PermissionUpdatedForUser;
use Gsebastiao\LaravelAuthz\Events\PermissionUpdatedInGroup;
use Gsebastiao\LaravelAuthz\Events\UserAddedToGroup;
use Gsebastiao\LaravelAuthz\Events\UserRemovedFromGroup;
use Gsebastiao\LaravelAuthz\Exceptions\AuthzException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Serviço principal do pacote: consulta e gerencia grupos e permissões.
 *
 * Use pelo Facade (Authz::hasPermission(...)), pelo helper (authz()->...)
 * ou por injeção de dependência. Não precisa instanciar manualmente.
 *
 * Regra de decisão de uma permissão (a primeira que se aplicar vence):
 *   1. Exceção individual do usuário NEGANDO     -> negado
 *   2. Exceção individual do usuário CONCEDENDO  -> concedido
 *   3. Algum grupo nega com is_absolute = true   -> negado
 *   4. Algum grupo concede                       -> concedido
 *   5. Só há negações comuns de grupo            -> negado
 *   6. Nada encontrado                           -> negado
 *
 * Só contam registros "vigentes": não apagados, com start_date <= hoje e
 * end_date vazio ou >= hoje, em grupos e membresias ativos (status = 1)
 * e em permissões ativas e visíveis ao tenant atual.
 */
class Authorization
{
    use Cacheable;
    use AuditsCrud;

    /** Campos que cada update*() aceita. Qualquer outro gera AuthzException. */
    protected const UPDATABLE = [
        'groups' => ['name', 'description', 'status'],
        'groups_users' => ['status', 'start_date', 'end_date', 'is_primary', 'observacao'],
        'permissions' => ['permission', 'module', 'action', 'label', 'description', 'order', 'status', 'tenant_id'],
        'permissions_groups' => ['is_granted', 'is_absolute', 'start_date', 'end_date'],
        'permissions_users' => ['is_granted', 'start_date', 'end_date'],
    ];

    /** @var \WeakMap<GateContract, true>|null */
    private static ?\WeakMap $gatesRegisteredOn = null;

    /* =================================================================
     * LEITURA
     * ================================================================= */

    /**
     * Grupos ativos do usuário (padrão: usuário logado).
     *
     * @return array<int, array{id: int, name: string, description: ?string}>
     */
    public function getUserGroups(?int $userId = null): array
    {
        $userId ??= Auth::id();

        if (!$userId) {
            return [];
        }

        return static::rememberForUser((int) $userId, 'groups', fn() => $this->computeUserGroups((int) $userId));
    }

    private function computeUserGroups(int $userId): array
    {
        $groups = static::table('groups');
        $groupsUsers = static::table('groups_users');

        $rows = DB::table($groupsUsers)
            ->join($groups, "{$groups}.id", '=', "{$groupsUsers}.group_id")
            ->where("{$groupsUsers}.user_id", $userId)
            ->where("{$groupsUsers}.status", 1)
            ->where("{$groups}.status", 1)
            ->whereNull("{$groups}.deleted_at")
            ->where(fn($q) => static::whereValid($q, $groupsUsers))
            // Sem tenant ativo: todos os grupos (modo single-tenant).
            ->when(static::activeTenantId(), fn($q, $tenantId) => $q->where("{$groups}.tenant_id", $tenantId))
            ->orderBy("{$groups}.id")
            ->get(["{$groups}.id", "{$groups}.name", "{$groups}.description"]);

        return $rows->unique('id')->map(fn($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'description' => $row->description,
        ])->values()->all();
    }

    /**
     * Permissões efetivas do usuário (padrão: usuário logado).
     *
     * - PermissionFormat::Both (padrão): [['id' => 3, 'permission' => 'financeiro.aprovar'], ...]
     * - PermissionFormat::Id:            [3, 7, ...]
     * - PermissionFormat::Permission:    ['financeiro.aprovar', ...]
     */
    public function getEffectivePermissions(?int $userId = null, PermissionFormat $format = PermissionFormat::Both): array
    {
        $userId ??= Auth::id();

        if (!$userId) {
            return [];
        }

        $granted = static::rememberForUser((int) $userId, 'permissions', fn() => $this->computeEffectivePermissions((int) $userId));

        return match ($format) {
            PermissionFormat::Both => $granted,
            PermissionFormat::Id => array_column($granted, 'id'),
            PermissionFormat::Permission => array_column($granted, 'permission'),
        };
    }

    private function computeEffectivePermissions(int $userId): array
    {
        $permissions = static::table('permissions');
        $permissionsUsers = static::table('permissions_users');
        $permissionsGroups = static::table('permissions_groups');

        // Passos 1 e 2 — exceções individuais. Se houver concessão e negação
        // para a mesma permissão, a negação vence.
        $userDecision = [];
        $userRows = DB::table($permissionsUsers)
            ->where('user_id', $userId)
            ->where(fn($q) => static::whereValid($q, $permissionsUsers))
            ->get(['permission_id', 'is_granted']);

        foreach ($userRows as $row) {
            $id = (int) $row->permission_id;
            $userDecision[$id] = ($userDecision[$id] ?? true) && (bool) $row->is_granted;
        }

        // Passos 3, 4 e 5 — regras dos grupos ativos do usuário.
        $absoluteDeny = [];
        $groupGrant = [];
        $groupIds = array_column($this->getUserGroups($userId), 'id');

        if ($groupIds) {
            $groupRows = DB::table($permissionsGroups)
                ->join($permissions, "{$permissions}.id", '=', "{$permissionsGroups}.permission_id")
                ->whereIn("{$permissionsGroups}.group_id", $groupIds)
                ->where(fn($q) => static::whereValid($q, $permissionsGroups))
                ->where(fn($q) => static::whereVisibleToTenant($q, $permissions))
                ->get(["{$permissionsGroups}.permission_id", "{$permissionsGroups}.is_granted", "{$permissionsGroups}.is_absolute"]);

            foreach ($groupRows as $row) {
                $id = (int) $row->permission_id;
                if ($row->is_granted) {
                    $groupGrant[$id] = true;
                } elseif ($row->is_absolute) {
                    $absoluteDeny[$id] = true;
                }
            }
        }

        $grantedIds = [];
        foreach (array_unique(array_merge(array_keys($userDecision), array_keys($groupGrant))) as $id) {
            $granted = array_key_exists($id, $userDecision)
                ? $userDecision[$id]
                : empty($absoluteDeny[$id]) && !empty($groupGrant[$id]);

            if ($granted) {
                $grantedIds[] = $id;
            }
        }

        if (!$grantedIds) {
            return [];
        }

        // Só permissões ativas, não apagadas e visíveis ao tenant atual.
        return DB::table($permissions)
            ->whereIn('id', $grantedIds)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where(fn($q) => static::whereVisibleToTenant($q, $permissions))
            ->orderBy('id')
            ->get(['id', 'permission'])
            ->map(fn($row) => ['id' => (int) $row->id, 'permission' => $row->permission])
            ->all();
    }

    /**
     * O usuário tem a permissão? Aceita o nome ('financeiro.aprovar') ou o
     * id (int). Uma string numérica ('7') é tratada como NOME, não como id.
     * Nomes são comparados sem diferenciar maiúsculas/minúsculas.
     */
    public function hasPermission(int|string $permission, ?int $userId = null): bool
    {
        if (is_int($permission)) {
            return in_array($permission, $this->getEffectivePermissions($userId, PermissionFormat::Id), true);
        }

        $wanted = static::normalizeName($permission);

        foreach ($this->getEffectivePermissions($userId, PermissionFormat::Permission) as $name) {
            if (static::normalizeName($name) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** Tem pelo menos UMA das permissões? */
    public function hasAnyPermission(array $permissions, ?int $userId = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission, $userId)) {
                return true;
            }
        }

        return false;
    }

    /** Tem TODAS as permissões? (lista vazia = false) */
    public function hasAllPermissions(array $permissions, ?int $userId = null): bool
    {
        if (!$permissions) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission, $userId)) {
                return false;
            }
        }

        return true;
    }

    /** Está no grupo? Aceita o nome ou o id (int) do grupo. */
    public function hasRole(int|string $role, ?int $userId = null): bool
    {
        $field = is_int($role) ? 'id' : 'name';

        return collect($this->getUserGroups($userId))->contains($field, $role);
    }

    /** Está em pelo menos UM dos grupos? */
    public function hasAnyRole(array $roles, ?int $userId = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $userId)) {
                return true;
            }
        }

        return false;
    }

    /** Está em TODOS os grupos? (lista vazia = false) */
    public function hasAllRoles(array $roles, ?int $userId = null): bool
    {
        if (!$roles) {
            return false;
        }

        foreach ($roles as $role) {
            if (!$this->hasRole($role, $userId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Permissões que podem ser atribuídas no tenant atual: as globais mais
     * as exclusivas do tenant ativo. Use para montar telas de "gerenciar
     * permissões". Sem tenant ativo, retorna o catálogo inteiro.
     */
    public function getAssignablePermissions(PermissionFormat $format = PermissionFormat::Both): array
    {
        $rows = DB::table(static::table('permissions'))
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->when(static::activeTenantId(), fn($q, $tenantId) => $q->where(
                fn($q2) => $q2->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)
            ))
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'permission']);

        return $rows->map(fn($row) => match ($format) {
            PermissionFormat::Id => (int) $row->id,
            PermissionFormat::Permission => $row->permission,
            PermissionFormat::Both => ['id' => (int) $row->id, 'permission' => $row->permission],
        })->all();
    }

    /* =================================================================
     * CRUD — Grupos
     * ================================================================= */

    /** Cria um grupo no tenant ativo. Retorna o id. */
    public function createGroup(string $name, ?string $description = null, int $status = 1): int
    {
        $table = static::table('groups');
        $tenantId = static::activeTenantId();
        $data = [
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $existing = DB::table($table)->where('name', $name)
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId), fn($q) => $q->whereNull('tenant_id'))
                ->first(['id', 'deleted_at']);

            if ($existing) {
                throw new AuthzException($existing->deleted_at
                    ? "Já existe um grupo apagado com o nome '{$name}' (#{$existing->id}). Use restoreGroup({$existing->id}) para recuperá-lo."
                    : "Já existe um grupo com o nome '{$name}' (#{$existing->id}).");
            }

            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent('groups', $id, 'created', static::withoutTimestamps($data));

                return $id;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('groups', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new GroupCreated($id, $data));

        return $id;
    }

    /** Campos aceitos: name, description, status. Retorna false se nada mudou. */
    public function updateGroup(int $id, array $data): bool
    {
        $result = $this->updateRow('groups', $id, $data, 'Grupo');

        if ($result) {
            [$old, $changes] = $result;
            event(new GroupUpdated($id, (array) $old, $changes));
            static::invalidateForGroup($id);
        }

        return (bool) $result;
    }

    /**
     * Apaga um grupo. Padrão: soft delete (pode ser recuperado com
     * restoreGroup). Com $purge = true, apaga de vez — junto com as
     * membresias e as regras de permissão do grupo.
     */
    public function deleteGroup(int $id, bool $purge = false): bool
    {
        $old = $this->findOrFail('groups', $id, 'Grupo', withTrashed: $purge);
        $memberIds = static::groupMemberIds($id);
        $table = static::table('groups');

        static::withAuditBatch(function () use ($table, $id, $purge, $old) {
            if ($purge) {
                DB::table(static::table('groups_users'))->where('group_id', $id)->delete();
                DB::table(static::table('permissions_groups'))->where('group_id', $id)->delete();
                DB::table($table)->where('id', $id)->delete();
            } else {
                DB::table($table)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            static::auditEvent('groups', $id, $purge ? 'purged' : 'deleted', (array) $old);
        });

        event(new GroupDeleted($id, (array) $old, $purge));
        static::invalidateForGroup($id, $memberIds);

        return true;
    }

    /** Recupera um grupo apagado com soft delete. */
    public function restoreGroup(int $id): bool
    {
        return $this->restoreRow('groups', $id, 'Grupo', fn() => static::invalidateForGroup($id));
    }

    /* =================================================================
     * CRUD — Membros de grupo
     * ================================================================= */

    /**
     * Coloca o usuário no grupo. Retorna o id da membresia.
     * Se ele já estiver no grupo, não duplica: atualiza a membresia
     * existente com as $options informadas e retorna o mesmo id.
     *
     * $options: status, start_date, end_date, is_primary, observacao.
     */
    public function addUserToGroup(int $userId, int $groupId, array $options = []): int
    {
        $this->findOrFail('groups', $groupId, 'Grupo');
        static::assertAllowedFields('groups_users', $options);
        $options = static::normalizeDates($options);
        $table = static::table('groups_users');

        $existingId = DB::table($table)->where('user_id', $userId)->where('group_id', $groupId)
            ->whereNull('deleted_at')->orderByDesc('id')->value('id');

        if ($existingId) {
            if ($options) {
                $this->updateGroupMembership((int) $existingId, $options);
            }

            return (int) $existingId;
        }

        $data = array_merge([
            'status' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'is_primary' => 0,
            'observacao' => null,
        ], $options, [
            'user_id' => $userId,
            'group_id' => $groupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent('groups_users', $id, 'created',
                    static::labeledChanges('groups_users', static::withoutTimestamps($data), ['group_id' => 'groups']));

                return $id;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('groups_users', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new UserAddedToGroup($id, $userId, $groupId, $data));
        static::invalidateForUser($userId);

        return $id;
    }

    /** Campos aceitos: status, start_date, end_date, is_primary, observacao. */
    public function updateGroupMembership(int $membershipId, array $data): bool
    {
        $result = $this->updateRow('groups_users', $membershipId, $data, 'Membresia');

        if ($result) {
            [$old, $changes] = $result;
            event(new GroupMembershipUpdated($membershipId, (array) $old, $changes));
            static::invalidateForUser((int) $old->user_id);
        }

        return (bool) $result;
    }

    /** Tira o usuário do grupo (pelo id da membresia). */
    public function removeUserFromGroup(int $membershipId, bool $purge = false): bool
    {
        $old = $this->deleteRow('groups_users', $membershipId, $purge, 'Membresia');

        event(new UserRemovedFromGroup($membershipId, (int) $old->user_id, (int) $old->group_id, $purge));
        static::invalidateForUser((int) $old->user_id);

        return true;
    }

    /* =================================================================
     * CRUD — Catálogo de permissões
     * ================================================================= */

    /**
     * Cria uma permissão. $tenantId = null (padrão) cria uma permissão
     * global; um id cria uma permissão exclusiva daquele tenant.
     */
    public function createPermission(
        string $permission,
        string $module,
        string $action,
        string $label,
        ?string $description = null,
        ?int $tenantId = null
    ): int {
        $table = static::table('permissions');
        $data = [
            'permission' => $permission,
            'module' => $module,
            'action' => $action,
            'label' => $label,
            'description' => $description,
            'status' => 1,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $existing = DB::table($table)->where('permission', $permission)->first(['id', 'deleted_at']);

            if ($existing) {
                throw new AuthzException($existing->deleted_at
                    ? "A permissão '{$permission}' já existe, mas está apagada (#{$existing->id}). Use restorePermission({$existing->id}) para recuperá-la."
                    : "A permissão '{$permission}' já existe (#{$existing->id}).");
            }

            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent('permissions', $id, 'created', static::withoutTimestamps($data));

                return $id;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('permissions', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new PermissionCreated($id, $data));

        return $id;
    }

    /** Campos aceitos: permission, module, action, label, description, order, status, tenant_id. */
    public function updatePermission(int $id, array $data): bool
    {
        $result = $this->updateRow('permissions', $id, $data, 'Permissão');

        if ($result) {
            [$old, $changes] = $result;
            event(new PermissionUpdated($id, (array) $old, $changes));
            static::invalidateEveryone(); // mudar o catálogo pode afetar qualquer usuário
        }

        return (bool) $result;
    }

    /** Apaga uma permissão (soft delete; $purge = true apaga de vez com as concessões). */
    public function deletePermission(int $id, bool $purge = false): bool
    {
        $old = $this->deleteRow('permissions', $id, $purge, 'Permissão');

        event(new PermissionDeleted($id, (array) $old, $purge));
        static::invalidateEveryone();

        return true;
    }

    /** Recupera uma permissão apagada com soft delete. */
    public function restorePermission(int $id): bool
    {
        return $this->restoreRow('permissions', $id, 'Permissão', fn() => static::invalidateEveryone());
    }

    /* =================================================================
     * CRUD — Regras de permissão por grupo
     * ================================================================= */

    /**
     * Define a regra do grupo para uma permissão. Retorna o id da regra.
     *
     * Padrão: concede. Para negar: ['is_granted' => false] e, se a negação
     * deve vencer outros grupos do usuário, ['is_absolute' => true].
     * Outras $options: start_date, end_date.
     *
     * Existe no máximo UMA regra por grupo+permissão: se ela já existir, é
     * atualizada (e recuperada, se tinha sido revogada) em vez de duplicar.
     */
    public function grantPermissionToGroup(int $groupId, int $permissionId, array $options = []): int
    {
        $group = $this->findOrFail('groups', $groupId, 'Grupo');
        $permission = $this->findOrFail('permissions', $permissionId, 'Permissão');
        static::assertAllowedFields('permissions_groups', $options);
        $options = static::normalizeDates($options);

        // Uma permissão exclusiva de um tenant só pode ir para grupos desse tenant.
        if ($permission->tenant_id !== null && $permission->tenant_id != $group->tenant_id) {
            event(new PermissionGrantToGroupRejected($groupId, $permissionId, (int) $permission->tenant_id,
                $group->tenant_id === null ? null : (int) $group->tenant_id));
            static::auditEvent('permissions_groups', 0, 'grant.rejected', [
                'group_id' => $groupId,
                'permission_id' => $permissionId,
                'permission_tenant_id' => $permission->tenant_id,
                'group_tenant_id' => $group->tenant_id,
            ]);

            throw new AuthzException(
                "A permissão #{$permissionId} é exclusiva do tenant #{$permission->tenant_id} e não pode ser " .
                "dada ao grupo #{$groupId} (tenant: " . ($group->tenant_id ?? 'nenhum') . ').'
            );
        }

        $table = static::table('permissions_groups');
        $rule = array_merge(['is_granted' => true, 'is_absolute' => false], $options);
        $existing = DB::table($table)->where('group_id', $groupId)->where('permission_id', $permissionId)->first();

        // Já existe e está ativa: atualiza.
        if ($existing && $existing->deleted_at === null) {
            $this->updateGroupPermission((int) $existing->id, $rule);

            return (int) $existing->id;
        }

        $data = array_merge(['start_date' => now()->toDateString(), 'end_date' => null], $rule, [
            'group_id' => $groupId,
            'permission_id' => $permissionId,
            'updated_at' => now(),
        ]);
        $refs = ['group_id' => 'groups', 'permission_id' => 'permissions'];

        try {
            $grantId = static::withAuditBatch(function () use ($table, $data, $existing, $refs) {
                if ($existing) { // estava revogada: recupera a mesma linha
                    DB::table($table)->where('id', $existing->id)->update($data + ['deleted_at' => null]);
                    static::auditEvent('permissions_groups', $existing->id, 'restored',
                        static::labeledChanges('permissions_groups', static::withoutTimestamps($data), $refs));

                    return (int) $existing->id;
                }

                $grantId = DB::table($table)->insertGetId($data + ['created_at' => now()]);
                static::auditEvent('permissions_groups', $grantId, 'created',
                    static::labeledChanges('permissions_groups', static::withoutTimestamps($data), $refs));

                return $grantId;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('permissions_groups', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new PermissionGrantedToGroup($grantId, $groupId, $permissionId, $data));
        static::invalidateForGroup($groupId);

        return $grantId;
    }

    /** Campos aceitos: is_granted, is_absolute, start_date, end_date. */
    public function updateGroupPermission(int $grantId, array $data): bool
    {
        $result = $this->updateRow('permissions_groups', $grantId, $data, 'Regra de grupo');

        if ($result) {
            [$old, $changes] = $result;
            event(new PermissionUpdatedInGroup($grantId, (array) $old, $changes));
            static::invalidateForGroup((int) $old->group_id);
        }

        return (bool) $result;
    }

    /** Remove a regra do grupo (pelo id da regra). */
    public function revokeGroupPermission(int $grantId, bool $purge = false): bool
    {
        $old = $this->deleteRow('permissions_groups', $grantId, $purge, 'Regra de grupo');

        event(new PermissionRevokedFromGroup($grantId, (int) $old->group_id, (int) $old->permission_id, $purge));
        static::invalidateForGroup((int) $old->group_id);

        return true;
    }

    /* =================================================================
     * CRUD — Exceções individuais (por usuário)
     * ================================================================= */

    /**
     * Cria uma exceção individual para o usuário. Retorna o id.
     * Padrão: concede. Para negar: ['is_granted' => false].
     * Outras $options: start_date, end_date.
     *
     * Existe no máximo UMA exceção ativa por usuário+permissão: se já
     * existir, é atualizada em vez de duplicar.
     */
    public function grantPermissionToUser(int $userId, int $permissionId, array $options = []): int
    {
        $this->findOrFail('permissions', $permissionId, 'Permissão');
        static::assertAllowedFields('permissions_users', $options);
        $options = static::normalizeDates($options);
        $table = static::table('permissions_users');
        $rule = array_merge(['is_granted' => true], $options);

        $activeIds = DB::table($table)->where('user_id', $userId)->where('permission_id', $permissionId)
            ->whereNull('deleted_at')->orderByDesc('id')->pluck('id')->map(fn($id) => (int) $id)->all();

        if ($activeIds) {
            $keepId = array_shift($activeIds);
            foreach ($activeIds as $duplicateId) { // limpa duplicatas antigas
                $this->revokeUserPermission($duplicateId);
            }
            $this->updateUserPermission($keepId, $rule);

            return $keepId;
        }

        $data = array_merge(['start_date' => now()->toDateString(), 'end_date' => null], $rule, [
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $overrideId = static::withAuditBatch(function () use ($table, $data) {
                $overrideId = DB::table($table)->insertGetId($data);
                static::auditEvent('permissions_users', $overrideId, 'created',
                    static::labeledChanges('permissions_users', static::withoutTimestamps($data), ['permission_id' => 'permissions']));

                return $overrideId;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('permissions_users', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new PermissionGrantedToUser($overrideId, $userId, $permissionId, $data));
        static::invalidateForUser($userId);

        return $overrideId;
    }

    /** Campos aceitos: is_granted, start_date, end_date. */
    public function updateUserPermission(int $overrideId, array $data): bool
    {
        $result = $this->updateRow('permissions_users', $overrideId, $data, 'Exceção individual');

        if ($result) {
            [$old, $changes] = $result;
            event(new PermissionUpdatedForUser($overrideId, (array) $old, $changes));
            static::invalidateForUser((int) $old->user_id);
        }

        return (bool) $result;
    }

    /** Remove a exceção individual (pelo id da exceção). */
    public function revokeUserPermission(int $overrideId, bool $purge = false): bool
    {
        $old = $this->deleteRow('permissions_users', $overrideId, $purge, 'Exceção individual');

        event(new PermissionRevokedFromUser($overrideId, (int) $old->user_id, (int) $old->permission_id, $purge));
        static::invalidateForUser((int) $old->user_id);

        return true;
    }

    /* =================================================================
     * Ajudantes públicos (usados pelos traits HasRoles/HasPermissions)
     * ================================================================= */

    /**
     * Converte nome ou id de grupo em id, respeitando o tenant ativo.
     * Lança AuthzException se não existir ou se o nome for ambíguo.
     */
    public function resolveGroupId(int|string $group): int
    {
        if (is_int($group)) {
            return $group;
        }

        $ids = DB::table(static::table('groups'))
            ->where('name', $group)
            ->whereNull('deleted_at')
            ->when(static::activeTenantId(), fn($q, $tenantId) => $q->where('tenant_id', $tenantId))
            ->pluck('id');

        if ($ids->isEmpty()) {
            throw new AuthzException("Grupo '{$group}' não encontrado.");
        }

        if ($ids->count() > 1) {
            throw new AuthzException(
                "Existe mais de um grupo chamado '{$group}' (ids: {$ids->implode(', ')}). Use o id do grupo."
            );
        }

        return (int) $ids->first();
    }

    /** Converte nome ou id de permissão em id. Lança AuthzException se não existir. */
    public function resolvePermissionId(int|string $permission): int
    {
        if (is_int($permission)) {
            return $permission;
        }

        $id = DB::table(static::table('permissions'))
            ->whereRaw('LOWER(permission) = ?', [static::normalizeName($permission)])
            ->whereNull('deleted_at')
            ->value('id');

        if (!$id) {
            throw new AuthzException("Permissão '{$permission}' não encontrada.");
        }

        return (int) $id;
    }

    /* =================================================================
     * Integração com Laravel: Gate e macro whereHasPermission
     * ================================================================= */

    /**
     * Liga as permissões ao Gate do Laravel: @can, Gate::allows(),
     * $user->can(), $this->authorize() e o middleware 'can:'.
     *
     * Não consulta o banco no boot. O pacote só responde quando nenhuma
     * Policy ou Gate::define() do seu projeto respondeu antes — as regras
     * do projeto sempre têm prioridade.
     */
    public function registerGates(?GateContract $gate = null): void
    {
        $gate ??= app(GateContract::class);
        self::$gatesRegisteredOn ??= new \WeakMap();

        if (isset(self::$gatesRegisteredOn[$gate])) {
            return;
        }

        self::$gatesRegisteredOn[$gate] = true;

        $gate->after(function ($user, $ability, $result) {
            if ($result !== null || !is_string($ability)) {
                return null;
            }

            $userId = $user->getAuthIdentifier();

            return is_numeric($userId) && app(static::class)->hasPermission($ability, (int) $userId) ? true : null;
        });
    }

    /**
     * Filtra uma query de usuários por permissão, sem N+1 e com a mesma
     * regra de hasPermission(). Normalmente usado pela macro:
     *   User::whereHasPermission('financeiro.aprovar')->get();
     */
    public function applyWhereHasPermission(
        \Illuminate\Database\Eloquent\Builder $query,
        string $permission,
        string $userIdColumn = 'id'
    ): \Illuminate\Database\Eloquent\Builder {
        $permissions = static::table('permissions');
        $permissionsUsers = static::table('permissions_users');
        $permissionsGroups = static::table('permissions_groups');
        $groupsUsers = static::table('groups_users');
        $groups = static::table('groups');
        $tenantId = static::activeTenantId();
        $name = static::normalizeName($permission);

        if (!str_contains($userIdColumn, '.')) {
            $userIdColumn = $query->getModel()->getTable() . '.' . $userIdColumn;
        }

        $matchPermission = function ($q, bool $mustBeActive) use ($name) {
            $q->whereRaw('LOWER(p.permission) = ?', [$name]);
            if ($mustBeActive) {
                $q->where('p.status', 1)->whereNull('p.deleted_at');
                static::whereVisibleToTenant($q, 'p');
            }
        };

        $userOverride = fn(bool $granted) => function ($q) use ($permissionsUsers, $permissions, $userIdColumn, $matchPermission, $granted) {
            $q->select(DB::raw(1))
                ->from("{$permissionsUsers} as pu")
                ->join("{$permissions} as p", 'p.id', '=', 'pu.permission_id')
                ->whereColumn('pu.user_id', $userIdColumn)
                ->where('pu.is_granted', $granted ? 1 : 0)
                ->where(fn($q2) => static::whereValid($q2, 'pu'))
                ->where(fn($q2) => $matchPermission($q2, $granted));
        };

        $userInGroup = function ($q) use ($groupsUsers, $groups, $userIdColumn, $tenantId) {
            $q->select(DB::raw(1))
                ->from("{$groupsUsers} as gu")
                ->join("{$groups} as g", 'g.id', '=', 'gu.group_id')
                ->whereColumn('gu.user_id', $userIdColumn)
                ->whereColumn('gu.group_id', 'pg.group_id')
                ->where('gu.status', 1)
                ->where('g.status', 1)
                ->whereNull('g.deleted_at')
                ->where(fn($q2) => static::whereValid($q2, 'gu'))
                ->when($tenantId, fn($q2, $t) => $q2->where('g.tenant_id', $t));
        };

        $groupRule = fn(bool $granted) => function ($q) use ($permissionsGroups, $permissions, $matchPermission, $userInGroup, $granted) {
            $q->select(DB::raw(1))
                ->from("{$permissionsGroups} as pg")
                ->join("{$permissions} as p", 'p.id', '=', 'pg.permission_id')
                ->where('pg.is_granted', $granted ? 1 : 0)
                ->when(!$granted, fn($q2) => $q2->where('pg.is_absolute', 1))
                ->where(fn($q2) => static::whereValid($q2, 'pg'))
                ->where(fn($q2) => $matchPermission($q2, true))
                ->whereExists($userInGroup);
        };

        // NÃO há negação individual E (há concessão individual OU
        // (nenhum grupo nega de forma absoluta E algum grupo concede)).
        return $query->whereNotExists($userOverride(false))
            ->where(fn($outer) => $outer
                ->whereExists($userOverride(true))
                ->orWhere(fn($q) => $q->whereNotExists($groupRule(false))->whereExists($groupRule(true))));
    }

    /* =================================================================
     * Internos
     * ================================================================= */

    /** Nome físico da tabela a partir da chave lógica ('groups' -> 'auth_groups'). */
    protected static function table(string $key): string
    {
        $table = config("authz.tables.{$key}");

        if (!is_string($table) || $table === '') {
            throw new \InvalidArgumentException("Nenhuma tabela configurada para a chave '{$key}' em authz.tables.");
        }

        return $table;
    }

    protected static function activeTenantId(): int|string|null
    {
        return app(TenantContext::class)->id();
    }

    protected static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** Registro vigente: não apagado, já começou e ainda não terminou. */
    protected static function whereValid($q, string $table): void
    {
        $today = now()->toDateString();
        // "< amanhã" em vez de "<= hoje": também funciona se start_date
        // tiver sido gravado com hora (ex: SQLite guardando texto).
        $tomorrow = now()->addDay()->toDateString();

        $q->whereNull("{$table}.deleted_at")
            ->where("{$table}.start_date", '<', $tomorrow)
            ->where(fn($q2) => $q2->whereNull("{$table}.end_date")->orWhere("{$table}.end_date", '>=', $today));
    }

    /** Permissão visível: global, ou exclusiva do tenant ativo. */
    protected static function whereVisibleToTenant($q, string $table): void
    {
        $tenantId = static::activeTenantId();

        $q->where(function ($q2) use ($table, $tenantId) {
            $q2->whereNull("{$table}.tenant_id");
            if ($tenantId !== null) {
                $q2->orWhere("{$table}.tenant_id", $tenantId);
            }
        });
    }

    protected function findOrFail(string $key, int $id, string $label, bool $withTrashed = false): object
    {
        $row = DB::table(static::table($key))->where('id', $id)
            ->when(!$withTrashed, fn($q) => $q->whereNull('deleted_at'))
            ->first();

        if (!$row) {
            throw new AuthzException("{$label} #{$id} não encontrado(a).");
        }

        return $row;
    }

    protected static function assertAllowedFields(string $key, array $data): void
    {
        $invalid = array_diff(array_keys($data), static::UPDATABLE[$key]);

        if ($invalid) {
            throw new AuthzException(sprintf(
                "Campo(s) não permitido(s): %s. Campos aceitos: %s.",
                implode(', ', $invalid),
                implode(', ', static::UPDATABLE[$key])
            ));
        }
    }

    /** Converte datas (Carbon/DateTime) em 'Y-m-d' nas colunas de vigência. */
    protected static function normalizeDates(array $data): array
    {
        foreach (['start_date', 'end_date'] as $field) {
            if (($data[$field] ?? null) instanceof \DateTimeInterface) {
                $data[$field] = $data[$field]->format('Y-m-d');
            }
        }

        return $data;
    }

    /**
     * Atualiza só o que mudou. Retorna [dadosAntigos, mudanças] ou null
     * se nada mudou (nesse caso nada é gravado, auditado nem disparado).
     */
    protected function updateRow(string $key, int $id, array $data, string $label): ?array
    {
        $old = $this->findOrFail($key, $id, $label);
        static::assertAllowedFields($key, $data);
        $data = static::normalizeDates($data);

        $before = static::stringifyForDiff(array_intersect_key((array) $old, $data));
        $after = static::stringifyForDiff($data);
        $changes = array_intersect_key($data, array_diff_assoc($after, $before));

        if (!$changes) {
            return null;
        }

        $table = static::table($key);

        try {
            static::withAuditBatch(function () use ($table, $key, $id, $changes) {
                DB::table($table)->where('id', $id)->update($changes + ['updated_at' => now()]);
                static::auditEvent($key, $id, 'updated', $changes);
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent($key, $id, 'updated.failed', $e, ['data' => $changes]);
            throw $e;
        }

        return [$old, $changes];
    }

    /** Soft delete (padrão) ou exclusão física. Retorna os dados antigos. */
    protected function deleteRow(string $key, int $id, bool $purge, string $label): object
    {
        $old = $this->findOrFail($key, $id, $label, withTrashed: $purge);
        $table = static::table($key);

        static::withAuditBatch(function () use ($table, $key, $id, $purge, $old) {
            $purge
                ? DB::table($table)->where('id', $id)->delete()
                : DB::table($table)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);

            static::auditEvent($key, $id, $purge ? 'purged' : 'deleted', (array) $old);
        });

        return $old;
    }

    protected function restoreRow(string $key, int $id, string $label, \Closure $invalidate): bool
    {
        $row = DB::table(static::table($key))->where('id', $id)->first();

        if (!$row) {
            throw new AuthzException("{$label} #{$id} não encontrado(a).");
        }

        if ($row->deleted_at === null) {
            return false; // não estava apagado
        }

        static::withAuditBatch(function () use ($key, $id) {
            DB::table(static::table($key))->where('id', $id)->update(['deleted_at' => null, 'updated_at' => now()]);
            static::auditEvent($key, $id, 'restored', ['deleted_at' => null]);
        });

        $invalidate();

        return true;
    }

    protected static function stringifyForDiff(array $data): array
    {
        return array_map(fn($value) => match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            $value === null => null,
            default => (string) $value,
        }, $data);
    }

    protected static function withoutTimestamps(array $data): array
    {
        unset($data['created_at'], $data['updated_at']);

        return $data;
    }

    /**
     * Troca uma FK crua (group_id: 3) por um valor legível
     * (group: {id: 3, label: "Financeiro"}) nos changes da auditoria.
     */
    protected static function labeledChanges(string $tableKey, array $data, array $refs = []): array
    {
        foreach ($refs as $fkColumn => $refTableKey) {
            $labelColumn = config("authz.audit.label_columns.{$refTableKey}");

            if (!isset($data[$fkColumn]) || $labelColumn === null) {
                continue;
            }

            $refId = $data[$fkColumn];
            $label = DB::table(static::table($refTableKey))->where('id', $refId)->value($labelColumn);
            $baseName = str_ends_with($fkColumn, '_id') ? substr($fkColumn, 0, -3) : $fkColumn;

            unset($data[$fkColumn]);
            $data[$baseName] = ['id' => $refId, 'label' => $label];
        }

        return $data;
    }
}
