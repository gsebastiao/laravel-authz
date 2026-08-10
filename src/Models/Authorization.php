<?php

namespace Gsebastiao\LaravelAuthz\Models;

use Gsebastiao\LaravelAuthz\Concerns\Auditable;
use Gsebastiao\LaravelAuthz\Concerns\Cacheable;
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Authorization extends Model
{
    use Auditable;
    use Cacheable;
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
                $q->whereNull("{$groupsUsers}.data_fim")
                    ->orWhere("{$groupsUsers}.data_fim", '>=', now()->toDateString());
            })
            // Único ponto de filtro de tenant nesta query. Quando
            // activeTenantId() é null (padrão do pacote), when() não
            // adiciona nada — comportamento idêntico ao original.
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
                $q->whereNull('data_fim')
                    ->orWhere('data_fim', '>=', now()->toDateString());
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
                    $q->whereNull("{$permissionsGroups}.data_fim")
                        ->orWhere("{$permissionsGroups}.data_fim", '>=', now()->toDateString());
                })
                // Defesa em profundidade: ignora concessão cuja permissão
                // é exclusiva de outro tenant. Não deveria existir uma
                // concessão dessas (a tela de gestão do tenant não
                // deveria nem oferecer a opção — ver getAssignablePermissions()),
                // mas se existir por bug ou edição direta no banco, não
                // é honrada aqui.
                ->when(self::activeTenantId(), function ($q, $tenantId) use ($permissions) {
                    $q->where(function ($q2) use ($permissions, $tenantId) {
                        $q2->whereNull("{$permissions}.tenant_id")
                            ->orWhere("{$permissions}.tenant_id", $tenantId);
                    });
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
     * não há particionamento a aplicar.
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

    // ==================== CRUD: GRUPOS ====================

    /**
     * Cria um grupo. tenant_id é sempre derivado do tenant ativo — não é
     * parâmetro aceito aqui de propósito, para não abrir uma forma de
     * criar um grupo apontando pra outro tenant por engano.
     */
    public function createGroup(string $name, ?string $description = null, int $status = 1): int
    {
        return static::auditedCreate('groups', [
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'tenant_id' => static::activeTenantId(),
        ], 'created');
    }

    public function updateGroup(int $id, array $data): bool
    {
        $result = static::auditedUpdate('groups', $id, $data, 'updated');
        static::invalidateForGroup($id);

        return $result;
    }

    public function deleteGroup(int $id, bool $purge = false): bool
    {
        $result = static::auditedDelete('groups', $id, $purge ? 'purged' : 'deleted', $purge);
        static::invalidateForGroup($id);

        return $result;
    }

    // ==================== CRUD: MEMBROS DE GRUPO ====================

    public function addUserToGroup(int $userId, int $groupId, array $options = []): int
    {
        $id = static::auditedCreate('groups_users', array_merge([
            'user_id' => $userId,
            'group_id' => $groupId,
            'status' => 1,
            'data_inicio' => now()->toDateString(),
            'data_fim' => null,
            'is_primary' => 0,
            'observacao' => null,
        ], $options), 'created', [
            'user_id' => $userId,
            'group' => ['id' => $groupId, 'label' => static::labelFor('groups', $groupId)],
        ]);

        static::invalidateForUser($userId);

        return $id;
    }

    public function updateGroupMembership(int $membershipId, array $data): bool
    {
        $userId = DB::table(static::table('groups_users'))->find($membershipId)?->user_id;

        $result = static::auditedUpdate('groups_users', $membershipId, $data, 'updated');

        if ($userId) {
            static::invalidateForUser($userId);
        }

        return $result;
    }

    public function removeUserFromGroup(int $membershipId, bool $purge = false): bool
    {
        $userId = DB::table(static::table('groups_users'))->find($membershipId)?->user_id;

        $result = static::auditedDelete('groups_users', $membershipId, $purge ? 'purged' : 'deleted', $purge);

        if ($userId) {
            static::invalidateForUser($userId);
        }

        return $result;
    }

    // ==================== CRUD: CATÁLOGO DE PERMISSÕES ====================

    /**
     * Cria uma permissão no catálogo. tenant_id fica explícito e default
     * null (global) de propósito — ao contrário de createGroup(), a
     * maioria das permissões deve ser global (definida pela plataforma);
     * exclusiva de um tenant é a exceção deliberada, não o padrão
     * implícito. Passe o id do tenant explicitamente quando for esse o
     * caso (ver README sobre permissões exclusivas de tenant).
     */
    public function createPermission(
        string $permission,
        string $module,
        string $action,
        string $label,
        ?string $description = null,
        ?int $tenantId = null
    ): int {
        return static::auditedCreate('permissions', [
            'permission' => $permission,
            'module' => $module,
            'action' => $action,
            'label' => $label,
            'description' => $description,
            'status' => 1,
            'tenant_id' => $tenantId,
        ], 'created');
    }

    /**
     * ATENÇÃO — não invalida cache automaticamente. Diferente das demais
     * funções de CRUD, editar o catálogo pode afetar qualquer usuário que
     * tenha esta permissão concedida por qualquer caminho (grupo ou
     * exceção individual, de qualquer tenant) — descobrir precisamente
     * quem exigiria uma consulta que cruza 3 tabelas, para uma ação de
     * admin rara. Se caching estiver ligado e isto importar pro seu caso,
     * chame Authorization::forgetUserCache() para os usuários conhecidos
     * afetados, ou aceite que o TTL é quem corrige isso.
     */
    public function updatePermission(int $id, array $data): bool
    {
        return static::auditedUpdate('permissions', $id, $data, 'updated');
    }

    /**
     * ATENÇÃO — mesma ressalva de updatePermission(): não invalida cache
     * automaticamente.
     */
    public function deletePermission(int $id, bool $purge = false): bool
    {
        return static::auditedDelete('permissions', $id, $purge ? 'purged' : 'deleted', $purge);
    }

    // ==================== CRUD: CONCESSÃO POR GRUPO ====================

    /**
     * Concede uma permissão a um grupo. Valida ANTES de gravar que a
     * permissão é visível ao tenant do grupo (global, ou exclusiva do
     * mesmo tenant) — fecha o mesmo buraco que a defesa em profundidade
     * de getEffectivePermissions() cobre na leitura, mas aqui na escrita,
     * antes da linha ruim chegar a existir. Falha na validação grava uma
     * entrada de auditoria rejeitada e lança exceção — não falha em
     * silêncio.
     *
     * @throws \RuntimeException se a permissão for exclusiva de um tenant diferente do grupo
     * @throws \InvalidArgumentException se o grupo ou a permissão não existirem
     */
    public function grantPermissionToGroup(int $groupId, int $permissionId, array $options = []): int
    {
        $group = DB::table(static::table('groups'))->find($groupId);
        $permission = DB::table(static::table('permissions'))->find($permissionId);

        if (!$group || !$permission) {
            throw new \InvalidArgumentException('Grupo ou permissão inexistente.');
        }

        if ($permission->tenant_id !== null && $permission->tenant_id != $group->tenant_id) {
            static::audit(static::table('permissions_groups'), 0, 'grant.rejected', [
                'error' => 'Permissão exclusiva de outro tenant',
                'group_id' => $groupId,
                'group_tenant_id' => $group->tenant_id,
                'permission_id' => $permissionId,
                'permission_tenant_id' => $permission->tenant_id,
            ]);

            throw new \RuntimeException(
                "Permissão #{$permissionId} é exclusiva do tenant #{$permission->tenant_id} e não pode ".
                "ser concedida ao grupo #{$groupId} (tenant #".($group->tenant_id ?? 'nenhum').")."
            );
        }

        $data = array_merge([
            'is_granted' => true,
            'is_absolute' => false,
            'data_inicio' => now()->toDateString(),
            'data_fim' => null,
        ], $options, [
            'group_id' => $groupId,
            'permission_id' => $permissionId,
        ]);

        // Usa as linhas já buscadas acima em vez de labelFor() (que
        // consultaria de novo) — só a coluna vem de config, pelo mesmo
        // motivo de labelFor(): projeto pode ter renomeado 'name'/'permission'.
        $groupLabelColumn = config('authz.audit.label_columns.groups', 'name');
        $permissionLabelColumn = config('authz.audit.label_columns.permissions', 'permission');

        $grantId = static::auditedCreate('permissions_groups', $data, 'granted', [
            'group' => ['id' => $groupId, 'label' => $group->{$groupLabelColumn} ?? null],
            'permission' => ['id' => $permissionId, 'label' => $permission->{$permissionLabelColumn} ?? null],
            'is_granted' => $data['is_granted'],
            'is_absolute' => $data['is_absolute'],
        ]);

        static::invalidateForGroup($groupId);

        return $grantId;
    }

    public function updateGroupPermission(int $grantId, array $data): bool
    {
        $groupId = DB::table(static::table('permissions_groups'))->find($grantId)?->group_id;

        $result = static::auditedUpdate('permissions_groups', $grantId, $data, 'updated');

        if ($groupId) {
            static::invalidateForGroup($groupId);
        }

        return $result;
    }

    public function revokeGroupPermission(int $grantId, bool $purge = false): bool
    {
        $groupId = DB::table(static::table('permissions_groups'))->find($grantId)?->group_id;

        $result = static::auditedDelete('permissions_groups', $grantId, $purge ? 'purged' : 'revoked', $purge);

        if ($groupId) {
            static::invalidateForGroup($groupId);
        }

        return $result;
    }

    // ==================== CRUD: EXCEÇÃO INDIVIDUAL ====================

    public function grantPermissionToUser(int $userId, int $permissionId, array $options = []): int
    {
        $data = array_merge([
            'is_granted' => true,
            'data_inicio' => now()->toDateString(),
            'data_fim' => null,
        ], $options, [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ]);

        $id = static::auditedCreate('permissions_users', $data, 'granted', [
            'user_id' => $userId,
            'permission' => [
                'id' => $permissionId,
                'label' => static::labelFor('permissions', $permissionId),
            ],
            'is_granted' => $data['is_granted'],
        ]);

        static::invalidateForUser($userId);

        return $id;
    }

    public function updateUserPermission(int $overrideId, array $data): bool
    {
        $userId = DB::table(static::table('permissions_users'))->find($overrideId)?->user_id;

        $result = static::auditedUpdate('permissions_users', $overrideId, $data, 'updated');

        if ($userId) {
            static::invalidateForUser($userId);
        }

        return $result;
    }

    public function revokeUserPermission(int $overrideId, bool $purge = false): bool
    {
        $userId = DB::table(static::table('permissions_users'))->find($overrideId)?->user_id;

        $result = static::auditedDelete('permissions_users', $overrideId, $purge ? 'purged' : 'revoked', $purge);

        if ($userId) {
            static::invalidateForUser($userId);
        }

        return $result;
    }

    // ==================== AUDITORIA: LEITURA ====================

    /**
     * Retorna a trilha de auditoria de um registro específico, mais
     * recente primeiro. $subjectType é o nome de tabela resolvido (o
     * mesmo valor gravado por audit() — ver static::table()).
     */
    public function getAuditTrail(string $subjectType, int $subjectId): array
    {
        return DB::table(static::auditTable())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($row) => (array) $row)
            ->toArray();
    }
}
