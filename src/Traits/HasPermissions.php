<?php

namespace Gsebastiao\LaravelAuthz\Traits;

use Gsebastiao\LaravelAuthz\Concerns\InteractsWithAuthz;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Illuminate\Support\Facades\DB;

/**
 * Métodos de permissão para o model de usuário.
 *
 * Toda permissão pode ser passada pelo nome ('financeiro.aprovar')
 * ou pelo id (int).
 */
trait HasPermissions
{
    use InteractsWithAuthz;

    public function hasPermission(int|string $permission): bool
    {
        return $this->authz()->hasPermission($permission, $this->authzUserId());
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return $this->authz()->hasAnyPermission($permissions, $this->authzUserId());
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return $this->authz()->hasAllPermissions($permissions, $this->authzUserId());
    }

    /** @param  'both'|'id'|'permission'|PermissionFormat  $format */
    public function getEffectivePermissions(string|PermissionFormat $format = 'both'): array
    {
        $format = $format instanceof PermissionFormat ? $format : (PermissionFormat::tryFrom($format) ?? PermissionFormat::Both);

        return $this->authz()->getEffectivePermissions($this->authzUserId(), $format);
    }

    /** Ex: ['financeiro.aprovar', 'usuario.criar'] */
    public function getPermissionNames(): array
    {
        return $this->getEffectivePermissions(PermissionFormat::Permission);
    }

    /** Ex: [3, 7] */
    public function getPermissionIds(): array
    {
        return $this->getEffectivePermissions(PermissionFormat::Id);
    }

    /** Dá a permissão diretamente ao usuário (vence os grupos). */
    public function grantPermission(int|string $permission, array $options = []): bool
    {
        $this->authz()->grantPermissionToUser(
            $this->authzUserId(),
            $this->authz()->resolvePermissionId($permission),
            array_merge($options, ['is_granted' => true])
        );

        return true;
    }

    /** Nega a permissão diretamente ao usuário (vence tudo, inclusive os grupos). */
    public function denyPermission(int|string $permission, array $options = []): bool
    {
        $this->authz()->grantPermissionToUser(
            $this->authzUserId(),
            $this->authz()->resolvePermissionId($permission),
            array_merge($options, ['is_granted' => false])
        );

        return true;
    }

    /**
     * Retira a concessão DIRETA. Se algum grupo do usuário der a mesma
     * permissão, ele continua tendo. Retorna false se não havia concessão direta.
     */
    public function revokePermission(int|string $permission): bool
    {
        return $this->removeDirectRules($permission, onlyGranted: true);
    }

    /**
     * Remove qualquer regra direta (concessão OU negação) da permissão,
     * deixando os grupos decidirem.
     */
    public function clearPermissionOverride(int|string $permission): bool
    {
        return $this->removeDirectRules($permission, onlyGranted: false);
    }

    /**
     * Deixa as concessões DIRETAS do usuário exatamente iguais à lista.
     * Não mexe em grupos nem em negações diretas.
     */
    public function syncPermissions(array $permissions): bool
    {
        $target = array_map(fn($p) => $this->authz()->resolvePermissionId($p), $permissions);

        foreach ($this->directPermissionIds() as $id) {
            if (!in_array($id, $target, true)) {
                $this->revokePermission($id);
            }
        }

        foreach (array_unique($target) as $id) {
            $this->grantPermission($id);
        }

        return true;
    }

    /** Ids das permissões concedidas diretamente (sem contar grupos). */
    public function directPermissionIds(): array
    {
        return DB::table(static::authzTable('permissions_users'))
            ->where('user_id', $this->authzUserId())
            ->where('is_granted', 1)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('permission_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    private function removeDirectRules(int|string $permission, bool $onlyGranted): bool
    {
        $ids = DB::table(static::authzTable('permissions_users'))
            ->where('user_id', $this->authzUserId())
            ->where('permission_id', $this->authz()->resolvePermissionId($permission))
            ->whereNull('deleted_at')
            ->when($onlyGranted, fn($q) => $q->where('is_granted', 1))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->authz()->revokeUserPermission((int) $id);
        }

        return $ids->isNotEmpty();
    }
}
