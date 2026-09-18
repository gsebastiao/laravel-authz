<?php

namespace Gsebastiao\LaravelAuthz\Traits;

use Gsebastiao\LaravelAuthz\Concerns\InteractsWithAuthz;
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Métodos de grupo ("papel"/"role") para o model de usuário.
 *
 * Todo grupo pode ser passado pelo nome ('financeiro') ou pelo id (int).
 * Com tenant ativo, nomes são procurados só dentro do tenant atual.
 */
trait HasRoles
{
    use InteractsWithAuthz;

    public function hasRole(int|string $role): bool
    {
        return $this->authz()->hasRole($role, $this->authzUserId());
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->authz()->hasAnyRole($roles, $this->authzUserId());
    }

    public function hasAllRoles(array $roles): bool
    {
        return $this->authz()->hasAllRoles($roles, $this->authzUserId());
    }

    /** Alias de hasRole(). */
    public function isInGroup(int|string $group): bool
    {
        return $this->hasRole($group);
    }

    /** @return array<int, array{id: int, name: string, description: ?string}> */
    public function getGroups(): array
    {
        return $this->authz()->getUserGroups($this->authzUserId());
    }

    public function getRoleNames(): array
    {
        return array_column($this->getGroups(), 'name');
    }

    public function getRoleIds(): array
    {
        return array_column($this->getGroups(), 'id');
    }

    /** Ex: [1 => 'financeiro', 4 => 'rh'] — pronto para um <select>. */
    public function getGroupsForSelect(): array
    {
        return array_column($this->getGroups(), 'name', 'id');
    }

    /**
     * Coloca o usuário no grupo. Se já estiver, não duplica.
     * $options: start_date, end_date, is_primary, observacao, status.
     */
    public function assignRole(int|string $role, array $options = []): bool
    {
        $this->authz()->addUserToGroup($this->authzUserId(), $this->authz()->resolveGroupId($role), $options);

        return true;
    }

    public function assignRoles(array $roles, array $options = []): bool
    {
        foreach ($roles as $role) {
            $this->assignRole($role, $options);
        }

        return true;
    }

    /** Tira o usuário do grupo. Retorna false se ele não estava no grupo. */
    public function removeRole(int|string $role): bool
    {
        $groupId = $this->authz()->resolveGroupId($role);
        $removed = false;

        foreach ($this->getMemberships() as $membership) {
            if ((int) $membership->group_id === $groupId) {
                $this->authz()->removeUserFromGroup((int) $membership->id);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Deixa o usuário exatamente nos grupos da lista (tira dos outros).
     * Lança erro se algum grupo da lista não existir — nada é alterado nesse caso.
     */
    public function syncRoles(array $roles): bool
    {
        $target = array_map(fn($role) => $this->authz()->resolveGroupId($role), $roles);

        DB::transaction(function () use ($target) {
            foreach ($this->getMemberships() as $membership) {
                if (!in_array((int) $membership->group_id, $target, true)) {
                    $this->authz()->removeUserFromGroup((int) $membership->id);
                }
            }

            foreach (array_unique($target) as $groupId) {
                $this->assignRole($groupId);
            }
        });

        return true;
    }

    /** Membresias do usuário (não apagadas), com todos os campos. */
    public function getMemberships(): array
    {
        return DB::table(static::authzTable('groups_users'))
            ->where('user_id', $this->authzUserId())
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** O usuário tem a permissão E ela vem de pelo menos um dos grupos dele? */
    public function hasRoleWithPermission(string $permission): bool
    {
        if (!$this->authz()->hasPermission($permission, $this->authzUserId())) {
            return false;
        }

        $groupIds = $this->getRoleIds();

        if (!$groupIds) {
            return false;
        }

        $today = now()->toDateString();

        return DB::table(static::authzTable('permissions_groups') . ' as pg')
            ->join(static::authzTable('permissions') . ' as p', 'pg.permission_id', '=', 'p.id')
            ->whereIn('pg.group_id', $groupIds)
            ->whereRaw('LOWER(p.permission) = ?', [mb_strtolower(trim($permission))])
            ->where('pg.is_granted', 1)
            ->whereNull('pg.deleted_at')
            ->where('pg.start_date', '<', now()->addDay()->toDateString())
            ->where(fn($q) => $q->whereNull('pg.end_date')->orWhere('pg.end_date', '>=', $today))
            ->exists();
    }

    /** Define o grupo principal do usuário (ele precisa já estar no grupo). */
    public function setPrimaryRole(int|string $role): bool
    {
        $groupId = $this->authz()->resolveGroupId($role);
        $memberships = collect($this->getMemberships());
        $target = $memberships->firstWhere('group_id', $groupId);

        if (!$target) {
            return false;
        }

        // Só mexe nos grupos do tenant atual (se houver tenant).
        $tenantId = app(TenantContext::class)->id();
        $groupIdsInScope = DB::table(static::authzTable('groups'))
            ->whereIn('id', $memberships->pluck('group_id'))
            ->when($tenantId, fn($q, $t) => $q->where('tenant_id', $t))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        DB::transaction(function () use ($memberships, $target, $groupIdsInScope) {
            foreach ($memberships as $membership) {
                if ($membership->id !== $target->id && $membership->is_primary
                    && in_array((int) $membership->group_id, $groupIdsInScope, true)) {
                    $this->authz()->updateGroupMembership((int) $membership->id, ['is_primary' => 0]);
                }
            }

            $this->authz()->updateGroupMembership((int) $target->id, ['is_primary' => 1]);
        });

        return true;
    }

    /** @return array{id: int, name: string, description: ?string}|null */
    public function getPrimaryRole(): ?array
    {
        $primaryIds = collect($this->getMemberships())->where('is_primary', 1)->pluck('group_id')->map(fn($id) => (int) $id);

        foreach ($this->getGroups() as $group) {
            if ($primaryIds->contains($group['id'])) {
                return $group;
            }
        }

        return null;
    }

    /** Todos os usuários ativos de um grupo. Ex: User::getUsersWithRole('financeiro') */
    public static function getUsersWithRole(int|string $role): Collection
    {
        try {
            $groupId = app(\Gsebastiao\LaravelAuthz\Models\Authorization::class)->resolveGroupId($role);
        } catch (\Gsebastiao\LaravelAuthz\Exceptions\AuthzException) {
            return collect();
        }

        $userIds = DB::table(static::authzTable('groups_users'))
            ->where('group_id', $groupId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->where('start_date', '<', now()->addDay()->toDateString())
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
            ->pluck('user_id');

        $model = new static();

        return static::query()->whereIn($model->getKeyName(), $userIds)->get();
    }
}
