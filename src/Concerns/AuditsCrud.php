<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Motor de auditoria aplicado a Authorization (não aos models — o
 * pacote não tem um model Eloquent por tabela para os 15 métodos de
 * CRUD, todos operam via query builder direto). Delega para a API
 * pública de gsebastiao/laravel-auditable quando disponível e
 * habilitada; vira no-op transparente caso contrário — exceto que o
 * pacote SEMPRE grava em transação (audite ou não), porque operações
 * como grantPermissionToGroup() tocam mais de uma tabela numa única
 * operação lógica.
 */
trait AuditsCrud
{
    /**
     * True apenas se auditoria estiver habilitada no config E o pacote
     * laravel-auditable estiver de fato instalado. Audit é uma classe
     * normal (chamada como Audit::log(), nunca `use Audit;`), então
     * class_exists() é a checagem correta aqui — ao contrário do trait
     * Auditable em AuditableModelSupport.php, que precisa trait_exists().
     */
    protected static function auditingActive(): bool
    {
        return (bool) config('authz.audit.enabled', false)
            && class_exists(\Gsebastiao\Auditable\Audit::class);
    }

    /**
     * Registra um evento de sucesso. No-op silencioso se auditoria não
     * estiver ativa.
     *
     * $tableKey é a CHAVE LÓGICA de tabela ('groups', não 'auth_groups')
     * — consistente com o resto da API pública do pacote
     * (applyAuditJoins() e getAuditTrail() também usam chave lógica).
     * Resolvida aqui para o nome físico antes de delegar a
     * Audit::log(), para que o subjectType gravado seja sempre a
     * tabela física de verdade — o mesmo valor que getAuditTrail()
     * consulta via Audit::trailFor().
     */
    protected static function auditEvent(
        string $tableKey,
        int|string $subjectId,
        string $event,
        array $changes = []
    ): void {
        if (!static::auditingActive()) {
            return;
        }

        \Gsebastiao\Auditable\Audit::log(
            subjectType: static::table($tableKey),
            subjectId: $subjectId,
            event: $event,
            changes: $changes,
        );
    }

    /**
     * Registra um evento de falha, com a exceção e contexto adicional.
     * Chamado fora de qualquer transação que tenha sofrido rollback —
     * ver withAuditBatch() e o padrão de uso nos métodos de CRUD de
     * Authorization. $tableKey segue a mesma convenção de chave lógica
     * de auditEvent() acima.
     */
    protected static function auditFailureEvent(
        string $tableKey,
        int|string $subjectId,
        string $event,
        \Throwable $exception,
        array $context = []
    ): void {
        if (!static::auditingActive()) {
            return;
        }

        \Gsebastiao\Auditable\Audit::logFailure(
            subjectType: static::table($tableKey),
            subjectId: $subjectId,
            event: $event,
            exception: $exception,
            context: $context,
        );
    }

    /**
     * O pacote sempre grava em transação, audite ou não — grava via
     * Audit::transaction() (que agrupa as escritas de negócio e os
     * registros de auditoria sob o mesmo batch) quando a auditoria
     * está ativa, ou via DB::transaction() simples caso contrário.
     *
     * auditEvent() de sucesso deve ser chamado DENTRO do $callback,
     * condicional ao resultado da própria escrita — nunca depois que
     * este método retorna, porque nesse ponto a transação (e o batch
     * que a acompanha) já fechou. auditFailureEvent() deve ficar FORA,
     * no catch de quem chama, porque precisa sobreviver ao rollback.
     */
    protected static function withAuditBatch(\Closure $callback): mixed
    {
        if (static::auditingActive()) {
            return \Gsebastiao\Auditable\Audit::transaction($callback);
        }

        return DB::transaction($callback);
    }

    /**
     * Histórico de auditoria de um registro. Recebe a CHAVE LÓGICA de
     * tabela ('groups', não 'auth_groups') — consistente com o resto
     * da API pública (applyAuditJoins() já usa chave lógica) — e resolve
     * internamente para o nome físico via static::table() antes de
     * delegar para Audit::trailFor(), que espera a tabela física.
     */
    public function getAuditTrail(string $tableKey, int|string $subjectId): array
    {
        if (!static::auditingActive()) {
            return [];
        }

        return \Gsebastiao\Auditable\Audit::trailFor(static::table($tableKey), $subjectId);
    }

    /**
     * Mapa 1:1 das 5 tabelas mutáveis do pacote para a classe de Model
     * Eloquent correspondente. AuditColumnJoiner::apply() do
     * laravel-auditable espera uma CLASSE DE MODEL como segundo
     * argumento, não uma string de tabela.
     */
    protected static function auditJoinsModelMap(): array
    {
        return [
            'groups' => \Gsebastiao\LaravelAuthz\Models\Group::class,
            'permissions' => \Gsebastiao\LaravelAuthz\Models\Permission::class,
            'groups_users' => \Gsebastiao\LaravelAuthz\Models\GroupUser::class,
            'permissions_groups' => \Gsebastiao\LaravelAuthz\Models\PermissionGroup::class,
            'permissions_users' => \Gsebastiao\LaravelAuthz\Models\PermissionUser::class,
        ];
    }

    /**
     * Anexa colunas {prefixo}{evento}_at/{prefixo}{evento}_by a uma
     * query via LEFT JOIN, sem N+1, para telas de listagem. $tableKey é
     * a chave lógica ('groups'), resolvida tanto para o nome físico da
     * tabela (usado para montar a query base se $query for null) quanto
     * para a classe de Model do mapa acima. No-op transparente (retorna
     * a query como veio, ou uma query base sem joins) se auditoria não
     * estiver ativa.
     */
    public function applyAuditJoins(string $tableKey, ?Builder $query = null, ?array $events = null): Builder
    {
        $table = static::table($tableKey);
        $query = $query ?? DB::table($table);

        if (!static::auditingActive()) {
            return $query;
        }

        $modelMap = static::auditJoinsModelMap();

        if (!isset($modelMap[$tableKey])) {
            throw new \InvalidArgumentException(
                "Nenhum model de auditoria mapeado para a chave '{$tableKey}'."
            );
        }

        $events = $events ?? config('authz.audit.join_events', ['created', 'updated']);

        return \Gsebastiao\Auditable\AuditColumnJoiner::apply(
            $query,
            $modelMap[$tableKey],
            events: $events,
            columnPrefix: config('authz.audit.column_prefix', ''),
        );
    }
}
