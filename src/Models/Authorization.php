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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Authorization extends Model
{
    use Cacheable;
    use AuditsCrud;

    /**
     * -----------------------------
     * Retorna os grupos ativos de um usuário (respeitando status,
     * soft delete, vigência de data e o tenant ativo, se houver).
     *
     * Passa por cache quando authz.cache.enabled estiver ligado — ver
     * Concerns\Cacheable. Com cache desligado (padrão), comportamento
     * idêntico a sempre consultar o banco.
     * -----------------------------
     */
    public function getUserGroups(?int $user_id = null): array
    {
        $userId = $user_id ?? Auth::id();

        if (!$userId) {
            return [];
        }

        return static::rememberForUser(
            $userId,
            static::groupsCacheKey($userId),
            fn() => $this->computeUserGroups($userId)
        );
    }

    private function computeUserGroups(int $userId): array
    {
        $groups = self::table('groups');
        $groupsUsers = self::table('groups_users');

        $rows = DB::table($groupsUsers)
            ->select("{$groups}.id", "{$groups}.name", "{$groups}.description")
            ->join($groups, "{$groups}.id", '=', "{$groupsUsers}.group_id")
            ->where("{$groupsUsers}.user_id", $userId)
            ->where("{$groupsUsers}.status", 1) // tinyInteger: 1 = ativo
            ->whereNull("{$groupsUsers}.deleted_at")
            ->whereNull("{$groups}.deleted_at")
            ->where(function ($q) use ($groupsUsers) {
                $q->whereNull("{$groupsUsers}.end_date")
                    ->orWhere("{$groupsUsers}.end_date", '>=', now()->toDateString());
            })
            // Modo compatibilidade single-tenant intencional: sem tenant
            // ativo, mostra tudo (não é a defesa em profundidade contra
            // dado corrompido — essa vive em computeEffectivePermissions()).
            ->when(self::activeTenantId(), function ($q, $tenantId) use ($groups) {
                $q->where("{$groups}.tenant_id", $tenantId);
            })
            ->get();

        return $rows->map(fn($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'description' => $row->description,
        ])->toArray();
    }

    /**
     * -----------------------------
     * Calcula as permissões efetivas do usuário, respeitando a ordem
     * de prioridade fechada na modelagem:
     *
     *   1. auth_permissions_users, is_granted = 0        -> NEGADO (usuário vence tudo)
     *   2. auth_permissions_users, is_granted = 1        -> CONCEDIDO
     *   3. algum grupo com is_granted = 0 E is_absolute = true -> NEGADO (deny forte de grupo)
     *   4. algum grupo com is_granted = 1                -> CONCEDIDO
     *   5. só restam negações fracas de grupo (is_absolute = false) -> NEGADO
     *   6. nenhum registro em lugar nenhum                -> NEGADO (padrão)
     *
     * O escopo de tenant chega aqui de forma transitiva: getUserGroups()
     * já filtra por tenant, então $groupIds abaixo já vem correto — não
     * é preciso reaplicar filtro nas tabelas de permissão por grupo.
     *
     * auth_permissions é global por padrão (tenant_id null), mas pode
     * ter permissões exclusivas de um tenant (tenant_id != null) — útil
     * quando um tenant pede uma funcionalidade sob medida e cada tenant
     * gerencia os próprios grupos/permissões via tela self-service. A
     * query de grupo abaixo já ignora qualquer concessão cuja permissão
     * não seja visível ao tenant ativo — proteção mesmo que a tela de
     * gestão do tenant já devesse ter impedido a concessão de existir.
     *
     * auth_permissions_users (exceção individual) permanece global neste
     * pacote. Se uma negação individual deve valer em todos os tenants
     * do usuário ou só no tenant onde foi criada é uma decisão de
     * negócio do projeto host, não do pacote — hoje ela é global, igual
     * ao comportamento original de origem.
     *
     * Único método de leitura de dados de permissão do pacote — o
     * chamador escolhe o formato do retorno via $format. O formato NÃO
     * afeta o que é cacheado: internamente sempre cacheia a forma
     * canônica (id + permission juntos) uma vez por usuário/tenant, e
     * converte para o formato pedido depois de ler — pedir Id numa
     * chamada e Permission na próxima não gera duas entradas de cache
     * pra mesma informação.
     *
     * @return array Conforme $format:
     *               - PermissionFormat::Id: array<int> de permission_ids
     *               - PermissionFormat::Permission: array<string> de nomes de permissão
     *               - PermissionFormat::Both (padrão): array<int, array{id: int, permission: string}>
     */
    public function getEffectivePermissions(
        ?int $user_id = null,
        PermissionFormat $format = PermissionFormat::Both
    ): array {
        $userId = $user_id ?? Auth::id();

        if (!$userId) {
            return [];
        }

        $granted = static::rememberForUser(
            $userId,
            static::permissionsCacheKey($userId),
            fn() => $this->computeEffectivePermissions($userId)
        );

        return match ($format) {
            PermissionFormat::Both => $granted,
            PermissionFormat::Id => array_column($granted, 'id'),
            PermissionFormat::Permission => array_column($granted, 'permission'),
        };
    }

    private function computeEffectivePermissions(int $userId): array
    {
        $permissions = self::table('permissions');
        $permissionsUsers = self::table('permissions_users');
        $permissionsGroups = self::table('permissions_groups');

        // Catálogo completo de permissões (id => string), usado só para
        // montar o retorno final com o nome legível. Não precisa filtro
        // de tenant aqui: por essa altura, $granted só contém ids que já
        // passaram pela checagem de visibilidade na query de grupo
        // abaixo — isto é só tradução id => nome.
        $catalog = DB::table($permissions)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->pluck('permission', 'id');

        // -----------------------------------------------------------
        // Passo 1 e 2: permissões individuais do usuário.
        // -----------------------------------------------------------
        $userRows = DB::table($permissionsUsers)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->get(['permission_id', 'is_granted']);

        $userDecision = []; // permission_id => true (concedido) | false (negado)
        foreach ($userRows as $row) {
            $userDecision[$row->permission_id] = (bool) $row->is_granted;
        }

        // -----------------------------------------------------------
        // Passos 3, 4 e 5: permissões via grupos.
        // -----------------------------------------------------------
        $groupIds = array_column($this->getUserGroups($userId), 'id');

        $groupHasAbsoluteDeny = []; // permission_id => true se algum grupo nega com is_absolute
        $groupHasGrant = [];        // permission_id => true se algum grupo concede
        $groupHasWeakDeny = [];     // permission_id => true se algum grupo nega sem is_absolute

        if (!empty($groupIds)) {
            $groupRows = DB::table($permissionsGroups)
                ->join($permissions, "{$permissions}.id", '=', "{$permissionsGroups}.permission_id")
                ->whereIn("{$permissionsGroups}.group_id", $groupIds)
                ->whereNull("{$permissionsGroups}.deleted_at")
                ->where(function ($q) use ($permissionsGroups) {
                    $q->whereNull("{$permissionsGroups}.end_date")
                        ->orWhere("{$permissionsGroups}.end_date", '>=', now()->toDateString());
                })
                // Defesa em profundidade: ignora concessão cuja permissão
                // é exclusiva de outro tenant. Não deveria existir uma
                // concessão dessas (a tela de gestão do tenant não
                // deveria nem oferecer a opção — ver getAssignablePermissions()),
                // mas se existir por bug ou edição direta no banco, não
                // é honrada aqui.
                //
                // CORRIGIDO: quando não há tenant ativo (NullTenantContext,
                // o padrão do pacote), a versão original usava
                // ->when(self::activeTenantId(), ...) — quando() só executa
                // o callback com condição truthy, então com tenant null
                // a defesa nunca era aplicada, deixando passar concessões
                // de permissão de QUALQUER tenant. Agora a condição
                // whereNull(tenant_id) roda sempre; a parte de tenant
                // específico só se soma quando há tenant ativo.
                ->where(function ($q2) use ($permissions) {
                    $q2->whereNull("{$permissions}.tenant_id");

                    if (self::activeTenantId() !== null) {
                        $q2->orWhere("{$permissions}.tenant_id", self::activeTenantId());
                    }
                })
                ->get([
                    "{$permissionsGroups}.permission_id",
                    "{$permissionsGroups}.is_granted",
                    "{$permissionsGroups}.is_absolute",
                ]);

            foreach ($groupRows as $row) {
                if ($row->is_granted) {
                    $groupHasGrant[$row->permission_id] = true;
                } elseif ($row->is_absolute) {
                    $groupHasAbsoluteDeny[$row->permission_id] = true;
                } else {
                    $groupHasWeakDeny[$row->permission_id] = true;
                }
            }
        }

        // -----------------------------------------------------------
        // Resolve a decisão final por permission_id, seguindo a
        // ordem de prioridade exata.
        // -----------------------------------------------------------
        $granted = [];

        $candidateIds = array_unique(array_merge(
            array_keys($userDecision),
            array_keys($groupHasAbsoluteDeny),
            array_keys($groupHasGrant),
            array_keys($groupHasWeakDeny)
        ));

        foreach ($candidateIds as $permissionId) {
            if (array_key_exists($permissionId, $userDecision)) {
                if ($userDecision[$permissionId] === true) {
                    $granted[$permissionId] = true;
                }
                continue;
            }

            if (!empty($groupHasAbsoluteDeny[$permissionId])) {
                continue; // negado, ponto final
            }

            if (!empty($groupHasGrant[$permissionId])) {
                $granted[$permissionId] = true;
                continue;
            }

            // Passo 5: só resta negação fraca sem nenhuma concessão => negado.
        }

        // Traduz permission_id => forma canônica (id + permission juntos),
        // ignorando ids que por algum motivo não existam mais no catálogo
        // (permissão apagada). A conversão para o formato pedido pelo
        // chamador acontece em getEffectivePermissions(), depois de ler
        // isto do cache ou computar fresco — nunca aqui.
        $result = [];
        foreach ($granted as $permissionId => $_) {
            if (!isset($catalog[$permissionId])) {
                continue;
            }

            $result[] = [
                'id' => $permissionId,
                'permission' => $catalog[$permissionId],
            ];
        }

        return $result;
    }

    /**
     * -----------------------------
     * Retorna o catálogo de permissões visíveis/atribuíveis no tenant
     * ativo: as globais (tenant_id null) mais as exclusivas do tenant
     * atual. É isto que a tela de "gerenciar grupos" de cada tenant deve
     * consultar para montar a lista de permissões que o gestor pode
     * conceder — sem essa filtragem, o gestor de outro tenant enxergaria
     * e poderia conceder a si mesmo uma permissão que não é dele.
     *
     * Sem tenant ativo (padrão do pacote), retorna o catálogo inteiro —
     * modo compatibilidade single-tenant intencional, não há
     * particionamento a aplicar.
     * -----------------------------
     */
    public function getAssignablePermissions(PermissionFormat $format = PermissionFormat::Both): array
    {
        $permissions = self::table('permissions');

        $rows = DB::table($permissions)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->when(self::activeTenantId(), function ($q, $tenantId) {
                $q->where(function ($q2) use ($tenantId) {
                    $q2->whereNull('tenant_id')
                        ->orWhere('tenant_id', $tenantId);
                });
            })
            ->get(['id', 'permission']);

        return $rows->map(fn($row) => match ($format) {
            PermissionFormat::Id => $row->id,
            PermissionFormat::Permission => $row->permission,
            PermissionFormat::Both => ['id' => $row->id, 'permission' => $row->permission],
        })->toArray();
    }

    /**
     * -----------------------------
     * Verifica se o usuário possui determinada permissão. Aceita id
     * (int) ou nome (string) — o tipo do argumento decide qual
     * comparação é feita. Uma string numérica ('7') continua sendo
     * tratada como nome, nunca como id — só um int literal ativa a
     * busca por id.
     * -----------------------------
     */
    public function hasPermission(int|string $permission, ?int $userId = null): bool
    {
        if (is_int($permission)) {
            $ids = $this->getEffectivePermissions($userId, PermissionFormat::Id);

            return in_array($permission, $ids, true);
        }

        $permission = trim(strtolower($permission));
        $permissions = array_map(
            static fn($p) => trim(strtolower($p)),
            $this->getEffectivePermissions($userId, PermissionFormat::Permission)
        );

        return in_array($permission, $permissions, true);
    }

    /**
     * -----------------------------
     * Ponto único de resolução de nome de tabela. Toda tabela usada
     * nesta classe passa por aqui — nunca uma string literal dentro de
     * DB::table(). Ver config/authz.php.
     * -----------------------------
     */
    protected static function table(string $key): string
    {
        $table = config("authz.tables.{$key}");

        if ($table === null) {
            throw new \InvalidArgumentException(
                "Nenhuma tabela configurada para a chave '{$key}' em authz.tables."
            );
        }

        return $table;
    }

    /**
     * -----------------------------
     * Ponto único de leitura do tenant ativo. Em modo single-tenant
     * (padrão do pacote), retorna sempre null e nenhum filtro de
     * tenant_id é aplicado em lugar nenhum desta classe.
     * -----------------------------
     */
    protected static function activeTenantId(): int|string|null
    {
        return app(TenantContext::class)->id();
    }

    /**
     * -----------------------------
     * Normaliza um array associativo para comparação de diff: datas
     * viram 'Y-m-d H:i:s', bool vira '1'/'0', resto vira string. Usado
     * só para decidir quais chaves mudaram entre o dado antigo e o novo
     * — nunca é o valor de fato gravado ou auditado.
     * -----------------------------
     */
    protected static function stringifyForDiff(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
            } elseif ($value === null) {
                $out[$key] = null;
            } else {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * -----------------------------
     * Troca uma FK crua (group_id: 3) por um valor legível
     * (group: {id: 3, label: "Financeiro"}) no changes de auditoria,
     * usando config('authz.audit.label_columns.*'). $refs mapeia o
     * nome da FK ('group_id') para a chave lógica de tabela ('groups')
     * de onde buscar o rótulo.
     * -----------------------------
     */
    protected static function labeledChanges(string $tableKey, array $data, array $refs = []): array
    {
        $changes = $data;

        foreach ($refs as $fkColumn => $refTableKey) {
            if (!array_key_exists($fkColumn, $data) || $data[$fkColumn] === null) {
                continue;
            }

            $labelColumn = config("authz.audit.label_columns.{$refTableKey}");

            if ($labelColumn === null) {
                continue;
            }

            $refId = $data[$fkColumn];
            $label = DB::table(static::table($refTableKey))->where('id', $refId)->value($labelColumn);

            $baseName = str_ends_with($fkColumn, '_id') ? substr($fkColumn, 0, -3) : $fkColumn;

            unset($changes[$fkColumn]);
            $changes[$baseName] = ['id' => $refId, 'label' => $label];
        }

        return $changes;
    }

    /**
     * =================================================================
     * CRUD — Grupos
     * =================================================================
     */

    public function createGroup(string $name, ?string $description = null, int $status = 1): int
    {
        $table = static::table('groups');
        $data = [
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'tenant_id' => static::activeTenantId(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent('groups', $id, 'created', static::labeledChanges('groups', $data));

                return $id;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('groups', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new GroupCreated($id, $data));

        return $id;
    }

    public function updateGroup(int $id, array $data): bool
    {
        $oldData = DB::table(static::table('groups'))->find($id);
        if (!$oldData) {
            throw new \RuntimeException("Grupo #{$id} não encontrado.");
        }

        $table = static::table('groups');
        $data['updated_at'] = now();

        $updated = static::withAuditBatch(function () use ($table, $id, $data, $oldData) {
            $updated = DB::table($table)->where('id', $id)->update($data);

            if ($updated) {
                static::auditEvent('groups', $id, 'updated', static::labeledChanges('groups', $data));
            }

            return $updated;
        });

        if ($updated) {
            event(new GroupUpdated($id, (array) $oldData, $data));
            static::invalidateForGroup($id);
        }

        return (bool) $updated;
    }

    public function deleteGroup(int $id, bool $purge = false): bool
    {
        $oldData = DB::table(static::table('groups'))->find($id);
        if (!$oldData) {
            throw new \RuntimeException("Grupo #{$id} não encontrado.");
        }

        $table = static::table('groups');

        $deleted = static::withAuditBatch(function () use ($table, $id, $purge, $oldData) {
            $deleted = $purge
                ? DB::table($table)->where('id', $id)->delete()
                : DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);

            if ($deleted) {
                static::auditEvent('groups', $id, $purge ? 'purged' : 'deleted', (array) $oldData);
            }

            return $deleted;
        });

        if ($deleted) {
            event(new GroupDeleted($id, (array) $oldData, $purge));
            static::invalidateForGroup($id);
        }

        return (bool) $deleted;
    }

    /**
     * =================================================================
     * CRUD — Membros de grupo
     * =================================================================
     */

    public function addUserToGroup(int $userId, int $groupId, array $options = []): int
    {
        $table = static::table('groups_users');
        $data = array_merge([
            'user_id' => $userId,
            'group_id' => $groupId,
            'status' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'is_primary' => 0,
            'observacao' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $options, [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        try {
            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent(
                    'groups_users',
                    $id,
                    'created',
                    static::labeledChanges('groups_users', $data, ['group_id' => 'groups'])
                );

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

    public function updateGroupMembership(int $membershipId, array $data): bool
    {
        $oldData = DB::table(static::table('groups_users'))->find($membershipId);
        if (!$oldData) {
            throw new \RuntimeException("Membresia #{$membershipId} não encontrada.");
        }

        $table = static::table('groups_users');
        $data['updated_at'] = now();

        $updated = static::withAuditBatch(function () use ($table, $membershipId, $data) {
            $updated = DB::table($table)->where('id', $membershipId)->update($data);

            if ($updated) {
                static::auditEvent(
                    'groups_users',
                    $membershipId,
                    'updated',
                    static::labeledChanges('groups_users', $data, ['group_id' => 'groups'])
                );
            }

            return $updated;
        });

        if ($updated) {
            event(new GroupMembershipUpdated($membershipId, (array) $oldData, $data));
            if ($oldData->user_id) {
                static::invalidateForUser($oldData->user_id);
            }
        }

        return (bool) $updated;
    }

    public function removeUserFromGroup(int $membershipId, bool $purge = false): bool
    {
        $oldData = DB::table(static::table('groups_users'))->find($membershipId);
        if (!$oldData) {
            throw new \RuntimeException("Membresia #{$membershipId} não encontrada.");
        }

        $table = static::table('groups_users');

        $deleted = static::withAuditBatch(function () use ($table, $membershipId, $purge, $oldData) {
            $deleted = $purge
                ? DB::table($table)->where('id', $membershipId)->delete()
                : DB::table($table)->where('id', $membershipId)->update(['deleted_at' => now()]);

            if ($deleted) {
                static::auditEvent('groups_users', $membershipId, $purge ? 'purged' : 'deleted', (array) $oldData);
            }

            return $deleted;
        });

        if ($deleted) {
            event(new UserRemovedFromGroup($membershipId, $oldData->user_id, $oldData->group_id, $purge));
            static::invalidateForUser($oldData->user_id);
        }

        return (bool) $deleted;
    }

    /**
     * =================================================================
     * CRUD — Catálogo de permissões
     * =================================================================
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
            $id = static::withAuditBatch(function () use ($table, $data) {
                $id = DB::table($table)->insertGetId($data);
                static::auditEvent('permissions', $id, 'created', $data);

                return $id;
            });
        } catch (\Throwable $e) {
            static::auditFailureEvent('permissions', 0, 'created.failed', $e, ['data' => $data]);
            throw $e;
        }

        event(new PermissionCreated($id, $data));

        return $id;
    }

    public function updatePermission(int $id, array $data): bool
    {
        $oldData = DB::table(static::table('permissions'))->find($id);
        if (!$oldData) {
            throw new \RuntimeException("Permissão #{$id} não encontrada.");
        }

        $table = static::table('permissions');
        $data['updated_at'] = now();

        $updated = static::withAuditBatch(function () use ($table, $id, $data) {
            $updated = DB::table($table)->where('id', $id)->update($data);

            if ($updated) {
                static::auditEvent('permissions', $id, 'updated', $data);
            }

            return $updated;
        });

        if ($updated) {
            event(new PermissionUpdated($id, (array) $oldData, $data));
        }

        return (bool) $updated;
    }

    public function deletePermission(int $id, bool $purge = false): bool
    {
        $oldData = DB::table(static::table('permissions'))->find($id);
        if (!$oldData) {
            throw new \RuntimeException("Permissão #{$id} não encontrada.");
        }

        $table = static::table('permissions');

        $deleted = static::withAuditBatch(function () use ($table, $id, $purge, $oldData) {
            $deleted = $purge
                ? DB::table($table)->where('id', $id)->delete()
                : DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);

            if ($deleted) {
                static::auditEvent('permissions', $id, $purge ? 'purged' : 'deleted', (array) $oldData);
            }

            return $deleted;
        });

        if ($deleted) {
            event(new PermissionDeleted($id, (array) $oldData, $purge));
        }

        return (bool) $deleted;
    }

    /**
     * =================================================================
     * CRUD — Concessão a grupo
     * =================================================================
     */

    public function grantPermissionToGroup(int $groupId, int $permissionId, array $options = []): int
    {
        $group = DB::table(static::table('groups'))->find($groupId);
        $permission = DB::table(static::table('permissions'))->find($permissionId);

        if (!$group || !$permission) {
            throw new \InvalidArgumentException('Grupo ou permissão inexistente.');
        }

        // Validação de visibilidade de tenant, antes de qualquer escrita:
        // fecha na escrita o mesmo buraco que a defesa em profundidade
        // de computeEffectivePermissions() já cobria na leitura.
        $permissionTenantId = $permission->tenant_id;
        $groupTenantId = $group->tenant_id;

        if ($permissionTenantId !== null && $permissionTenantId != $groupTenantId) {
            event(new PermissionGrantToGroupRejected($groupId, $permissionId, $permissionTenantId, $groupTenantId));
            static::auditEvent('permissions_groups', 0, 'grant.rejected', [
                'group_id' => $groupId,
                'permission_id' => $permissionId,
                'permission_tenant_id' => $permissionTenantId,
                'group_tenant_id' => $groupTenantId,
            ]);

            throw new \RuntimeException(
                "Permissão #{$permissionId} é exclusiva do tenant #{$permissionTenantId} e não pode " .
                "ser concedida ao grupo #{$groupId} (tenant #" . ($groupTenantId ?? 'nenhum') . ")."
            );
        }

        $table = static::table('permissions_groups');
        $data = array_merge([
            'is_granted' => true,
            'is_absolute' => false,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $options, [
            'group_id' => $groupId,
            'permission_id' => $permissionId,
        ]);

        try {
            $grantId = static::withAuditBatch(function () use ($table, $data) {
                $grantId = DB::table($table)->insertGetId($data);
                static::auditEvent(
                    'permissions_groups',
                    $grantId,
                    'created',
                    static::labeledChanges('permissions_groups', $data, [
                        'group_id' => 'groups',
                        'permission_id' => 'permissions',
                    ])
                );

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

    public function updateGroupPermission(int $grantId, array $data): bool
    {
        $oldData = DB::table(static::table('permissions_groups'))->find($grantId);
        if (!$oldData) {
            throw new \RuntimeException("Concessão #{$grantId} não encontrada.");
        }

        $table = static::table('permissions_groups');
        $data['updated_at'] = now();

        $updated = static::withAuditBatch(function () use ($table, $grantId, $data) {
            $updated = DB::table($table)->where('id', $grantId)->update($data);

            if ($updated) {
                static::auditEvent(
                    'permissions_groups',
                    $grantId,
                    'updated',
                    static::labeledChanges('permissions_groups', $data, [
                        'group_id' => 'groups',
                        'permission_id' => 'permissions',
                    ])
                );
            }

            return $updated;
        });

        if ($updated) {
            event(new PermissionUpdatedInGroup($grantId, (array) $oldData, $data));
            static::invalidateForGroup($oldData->group_id);
        }

        return (bool) $updated;
    }

    public function revokeGroupPermission(int $grantId, bool $purge = false): bool
    {
        $oldData = DB::table(static::table('permissions_groups'))->find($grantId);
        if (!$oldData) {
            throw new \RuntimeException("Concessão #{$grantId} não encontrada.");
        }

        $table = static::table('permissions_groups');

        $deleted = static::withAuditBatch(function () use ($table, $grantId, $purge, $oldData) {
            $deleted = $purge
                ? DB::table($table)->where('id', $grantId)->delete()
                : DB::table($table)->where('id', $grantId)->update(['deleted_at' => now()]);

            if ($deleted) {
                static::auditEvent('permissions_groups', $grantId, $purge ? 'purged' : 'deleted', (array) $oldData);
            }

            return $deleted;
        });

        if ($deleted) {
            event(new PermissionRevokedFromGroup($grantId, $oldData->group_id, $oldData->permission_id, $purge));
            static::invalidateForGroup($oldData->group_id);
        }

        return (bool) $deleted;
    }

    /**
     * =================================================================
     * CRUD — Exceção individual
     * =================================================================
     */

    public function grantPermissionToUser(int $userId, int $permissionId, array $options = []): int
    {
        $table = static::table('permissions_users');
        $data = array_merge([
            'is_granted' => true,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $options, [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ]);

        try {
            $overrideId = static::withAuditBatch(function () use ($table, $data) {
                $overrideId = DB::table($table)->insertGetId($data);
                static::auditEvent(
                    'permissions_users',
                    $overrideId,
                    'created',
                    static::labeledChanges('permissions_users', $data, ['permission_id' => 'permissions'])
                );

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

    public function updateUserPermission(int $overrideId, array $data): bool
    {
        $oldData = DB::table(static::table('permissions_users'))->find($overrideId);
        if (!$oldData) {
            throw new \RuntimeException("Exceção #{$overrideId} não encontrada.");
        }

        $table = static::table('permissions_users');
        $data['updated_at'] = now();

        $updated = static::withAuditBatch(function () use ($table, $overrideId, $data) {
            $updated = DB::table($table)->where('id', $overrideId)->update($data);

            if ($updated) {
                static::auditEvent(
                    'permissions_users',
                    $overrideId,
                    'updated',
                    static::labeledChanges('permissions_users', $data, ['permission_id' => 'permissions'])
                );
            }

            return $updated;
        });

        if ($updated) {
            event(new PermissionUpdatedForUser($overrideId, (array) $oldData, $data));
            static::invalidateForUser($oldData->user_id);
        }

        return (bool) $updated;
    }

    public function revokeUserPermission(int $overrideId, bool $purge = false): bool
    {
        $oldData = DB::table(static::table('permissions_users'))->find($overrideId);
        if (!$oldData) {
            throw new \RuntimeException("Exceção #{$overrideId} não encontrada.");
        }

        $table = static::table('permissions_users');

        $deleted = static::withAuditBatch(function () use ($table, $overrideId, $purge, $oldData) {
            $deleted = $purge
                ? DB::table($table)->where('id', $overrideId)->delete()
                : DB::table($table)->where('id', $overrideId)->update(['deleted_at' => now()]);

            if ($deleted) {
                static::auditEvent('permissions_users', $overrideId, $purge ? 'purged' : 'deleted', (array) $oldData);
            }

            return $deleted;
        });

        if ($deleted) {
            event(new PermissionRevokedFromUser($overrideId, $oldData->user_id, $oldData->permission_id, $purge));
            static::invalidateForUser($oldData->user_id);
        }

        return (bool) $deleted;
    }

    /**
     * =================================================================
     * Gates, macro de query
     * =================================================================
     */

    /**
     * Registra um Gate::define() por permissão do catálogo, nomeado
     * igual à própria string de permissão. Chamado pelo ServiceProvider
     * condicional a config('authz.gates.auto_register').
     */
    public function registerGates(): void
    {
        $permissions = $this->getAssignablePermissions(PermissionFormat::Both);

        foreach ($permissions as $permission) {
            Gate::define($permission['permission'], function ($user) use ($permission) {
                return $this->hasPermission($permission['permission'], $user->id);
            });
        }
    }

    /**
     * Implementa o filtro de listagem 'whereHasPermission' via
     * whereExists/whereNotExists aninhados, sem N+1 — replica a MESMA
     * cascata de precedência de computeEffectivePermissions() (exceção
     * individual > negação absoluta de grupo > concessão de grupo >
     * negação fraca > padrão negado), mas como predicado SQL em vez de
     * ler e comparar em PHP.
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

        $activeTenantId = static::activeTenantId();

        // Qualifica $userIdColumn com a tabela da query externa quando
        // vier sem qualificação ('id' em vez de 'users.id') — evita
        // "ambiguous column name" dentro das subqueries abaixo, que
        // sempre referenciam colunas de outras tabelas também chamadas
        // 'id'/'user_id'.
        if (!str_contains($userIdColumn, '.')) {
            $userIdColumn = $query->getModel()->getTable() . '.' . $userIdColumn;
        }

        $userGrantedSub = function ($q) use ($permissionsUsers, $permissions, $permission, $userIdColumn) {
            $q->select(DB::raw(1))
                ->from("{$permissionsUsers} as pu")
                ->join("{$permissions} as p", 'p.id', '=', 'pu.permission_id')
                ->whereColumn('pu.user_id', $userIdColumn)
                ->where('p.permission', $permission)
                ->where('pu.is_granted', true)
                ->whereNull('pu.deleted_at')
                ->where(function ($q2) {
                    $q2->whereNull('pu.end_date')->orWhere('pu.end_date', '>=', now()->toDateString());
                });
        };

        $userDeniedSub = function ($q) use ($permissionsUsers, $permissions, $permission, $userIdColumn) {
            $q->select(DB::raw(1))
                ->from("{$permissionsUsers} as pu")
                ->join("{$permissions} as p", 'p.id', '=', 'pu.permission_id')
                ->whereColumn('pu.user_id', $userIdColumn)
                ->where('p.permission', $permission)
                ->where('pu.is_granted', false)
                ->whereNull('pu.deleted_at')
                ->where(function ($q2) {
                    $q2->whereNull('pu.end_date')->orWhere('pu.end_date', '>=', now()->toDateString());
                });
        };

        $activeGroupsSub = function ($q) use ($groupsUsers, $groups, $userIdColumn, $activeTenantId) {
            $q->select(DB::raw(1))
                ->from("{$groupsUsers} as gu")
                ->join("{$groups} as g", 'g.id', '=', 'gu.group_id')
                ->whereColumn('gu.user_id', $userIdColumn)
                ->whereColumn('gu.group_id', 'pg.group_id')
                ->where('gu.status', 1)
                ->whereNull('gu.deleted_at')
                ->whereNull('g.deleted_at')
                ->where(function ($q2) {
                    $q2->whereNull('gu.end_date')->orWhere('gu.end_date', '>=', now()->toDateString());
                })
                ->when($activeTenantId, function ($q2, $tenantId) {
                    $q2->where('g.tenant_id', $tenantId);
                });
        };

        $groupAbsoluteDenySub = function ($q) use (
            $permissionsGroups,
            $permissions,
            $permission,
            $activeGroupsSub,
            $activeTenantId
        ) {
            $q->select(DB::raw(1))
                ->from("{$permissionsGroups} as pg")
                ->join("{$permissions} as p", 'p.id', '=', 'pg.permission_id')
                ->where('p.permission', $permission)
                ->where('pg.is_granted', false)
                ->where('pg.is_absolute', true)
                ->whereNull('pg.deleted_at')
                ->where(function ($q2) {
                    $q2->whereNull('pg.end_date')->orWhere('pg.end_date', '>=', now()->toDateString());
                })
                ->where(function ($q2) use ($activeTenantId) {
                    $q2->whereNull('p.tenant_id');

                    if ($activeTenantId !== null) {
                        $q2->orWhere('p.tenant_id', $activeTenantId);
                    }
                })
                ->whereExists($activeGroupsSub);
        };

        $groupGrantSub = function ($q) use (
            $permissionsGroups,
            $permissions,
            $permission,
            $activeGroupsSub,
            $activeTenantId
        ) {
            $q->select(DB::raw(1))
                ->from("{$permissionsGroups} as pg")
                ->join("{$permissions} as p", 'p.id', '=', 'pg.permission_id')
                ->where('p.permission', $permission)
                ->where('pg.is_granted', true)
                ->whereNull('pg.deleted_at')
                ->where(function ($q2) {
                    $q2->whereNull('pg.end_date')->orWhere('pg.end_date', '>=', now()->toDateString());
                })
                ->where(function ($q2) use ($activeTenantId) {
                    $q2->whereNull('p.tenant_id');

                    if ($activeTenantId !== null) {
                        $q2->orWhere('p.tenant_id', $activeTenantId);
                    }
                })
                ->whereExists($activeGroupsSub);
        };

        return $query->where(function ($outer) use ($userGrantedSub, $userDeniedSub, $groupAbsoluteDenySub, $groupGrantSub) {
            // Exceção individual concede => sempre vence.
            $outer->whereExists($userGrantedSub)
                // OU: sem exceção individual negando, sem deny absoluto de
                // grupo, e algum grupo concede.
                ->orWhere(function ($q) use ($userDeniedSub, $groupAbsoluteDenySub, $groupGrantSub) {
                    $q->whereNotExists($userDeniedSub)
                        ->whereNotExists($groupAbsoluteDenySub)
                        ->whereExists($groupGrantSub);
                });
        });
    }
}
