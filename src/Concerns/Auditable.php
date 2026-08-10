<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

use Gsebastiao\LaravelAuthz\Support\AuditBatch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Motor de auditoria usado pelas funções de CRUD de Authorization. Porta o
 * comportamento de BaseModel (transação, batch agrupado, auditoria em
 * sucesso e falha, nunca engole exceção) adaptado para uma classe que
 * opera em várias tabelas via query builder cru, não uma instância
 * Eloquent por tabela.
 *
 * Duas adaptações deliberadas em relação ao BaseModel de referência:
 * - Sem coluna de status/tipo em auth_audit_table: sucesso/falha fica
 *   codificado no próprio $event ('created' vs 'created.failed').
 * - Sem sistema de resolveMap genérico: labelFor() resolve só as duas FKs
 *   que existem nestas 5 tabelas (group_id, permission_id), não um
 *   sistema aberto para qualquer model da aplicação.
 */
trait Auditable
{
    protected static function auditTable(): string
    {
        return config('authz.tables.audit', 'auth_audit_table');
    }

    protected static function auditEnabled(): bool
    {
        return (bool) config('authz.audit.enabled', true);
    }

    protected static function auditRequired(): bool
    {
        return (bool) config('authz.audit.required', true);
    }

    /**
     * Grava uma linha em auth_audit_table. $subjectType é o nome resolvido
     * da tabela auditada (não o nome de uma classe — não há uma classe
     * Eloquent por tabela aqui).
     */
    protected static function audit(
        string $subjectType,
        int|string $subjectId,
        string $event,
        array $changes,
        ?string $batchId = null
    ): void {
        if (!static::auditEnabled()) {
            return;
        }

        $failed = str_ends_with($event, '.failed');

        $auditData = [
            'batch' => AuditBatch::resolve($batchId),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'changes' => static::formatChanges($changes, $failed),
            'debug_info' => $failed ? static::formatDebugInfo($changes) : null,
            // Auth::id() sem fallback de propósito: o pacote não presume
            // que algum id fixo representa "usuário sistema" — isso é
            // convenção de projeto, não do pacote. null = ação sem
            // usuário autenticado no momento (job, comando artisan, etc).
            'user_id' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table(static::auditTable())->insert($auditData);
        } catch (Throwable $e) {
            Log::error('[AuthzAuditFailed] ' . $e->getMessage());

            if (static::auditRequired()) {
                throw new \RuntimeException(
                    "Falha ao gravar auditoria para {$subjectType}#{$subjectId}",
                    0,
                    $e
                );
            }
        }
    }

    protected static function formatChanges(array $changes, bool $failed): string
    {
        if ($failed) {
            return json_encode([
                'message' => $changes['error'] ?? 'Operação falhou',
            ], JSON_UNESCAPED_UNICODE);
        }

        if (empty($changes)) {
            return json_encode(['message' => 'Nenhuma alteração detectada'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($changes, JSON_UNESCAPED_UNICODE);
    }

    protected static function formatDebugInfo(array $changes): string
    {
        $debug = [
            'timestamp' => now()->toDateTimeString(),
            'environment' => app()->environment(),
            'error' => [
                'message' => $changes['error'] ?? null,
                'code' => $changes['error_code'] ?? null,
                'line' => $changes['error_line'] ?? null,
                'file' => $changes['error_file'] ?? null,
                'class' => $changes['error_class'] ?? null,
            ],
            'data' => $changes['data'] ?? null,
            'old_data' => $changes['old_data'] ?? null,
            'trace' => isset($changes['trace'])
                ? implode("\n", array_slice(explode("\n", $changes['trace']), 0, 20))
                : null,
            'request' => app()->runningInConsole() ? null : [
                'method' => request()->method(),
                'uri' => request()->path(),
                'ip' => request()->ip(),
            ],
        ];

        return json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Resolve o rótulo legível de uma linha em uma das tabelas do pacote,
     * pelo id. A coluna vem de config('authz.audit.label_columns.{tableKey}'),
     * editável por projeto (ex: 'nome' em vez de 'name') — $labelColumn só
     * precisa ser passado para sobrescrever a config numa chamada pontual.
     * Limitado às duas FKs que existem entre estas 5 tabelas (group_id,
     * permission_id) — não é um sistema genérico para qualquer tabela da
     * aplicação.
     */
    protected static function labelFor(string $tableKey, int $id, ?string $labelColumn = null): ?string
    {
        $labelColumn = $labelColumn ?? config("authz.audit.label_columns.{$tableKey}", 'name');
        $row = DB::table(static::table($tableKey))->find($id);

        return $row?->{$labelColumn};
    }

    /**
     * Insere um registro em $tableKey e audita, dentro de uma transação —
     * se o insert ou a própria gravação da auditoria falhar, os dois
     * desfazem juntos. Em falha, grava uma entrada '{$event}.failed' com
     * contexto rico (erro, dados, trace) e relança a exceção — nunca
     * engole silenciosamente.
     *
     * $auditPayload permite que o chamador registre um diff mais legível
     * (ex: com labels resolvidos) em vez do array bruto inserido; se
     * omitido, audita ['new' => $data].
     */
    protected static function auditedCreate(
        string $tableKey,
        array $data,
        string $event,
        ?array $auditPayload = null,
        ?string $batchId = null
    ): int {
        $table = static::table($tableKey);
        $batchId = AuditBatch::resolve($batchId);

        $data['created_at'] = $data['created_at'] ?? now();
        $data['updated_at'] = $data['updated_at'] ?? now();

        try {
            return DB::transaction(function () use ($table, $data, $event, $batchId, $auditPayload) {
                $id = DB::table($table)->insertGetId($data);

                static::audit($table, $id, $event, $auditPayload ?? ['new' => $data], $batchId);

                return $id;
            });
        } catch (Throwable $e) {
            static::audit($table, 0, "{$event}.failed", [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'error_class' => get_class($e),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ], $batchId);

            throw $e;
        } finally {
            AuditBatch::forget();
        }
    }

    /**
     * Atualiza um registro em $tableKey e audita só os campos que
     * realmente mudaram (compara contra o valor antigo, campo a campo).
     */
    protected static function auditedUpdate(
        string $tableKey,
        int $id,
        array $data,
        string $event,
        ?array $auditPayload = null,
        ?string $batchId = null
    ): bool {
        $table = static::table($tableKey);
        $batchId = AuditBatch::resolve($batchId);

        $existing = DB::table($table)->find($id);

        if (!$existing) {
            static::audit($table, $id, "{$event}.failed", [
                'error' => "Registro #{$id} não encontrado em {$table}",
            ], $batchId);

            throw new \RuntimeException("Registro #{$id} não encontrado em {$table}.");
        }

        $old = (array) $existing;

        try {
            return DB::transaction(function () use ($table, $id, $data, $old, $event, $batchId, $auditPayload) {
                $data['updated_at'] = $data['updated_at'] ?? now();

                DB::table($table)->where('id', $id)->update($data);

                if ($auditPayload !== null) {
                    static::audit($table, $id, $event, $auditPayload, $batchId);

                    return true;
                }

                $diff = [];
                foreach ($data as $field => $newVal) {
                    $oldVal = $old[$field] ?? null;
                    if ($oldVal != $newVal) {
                        $diff[$field] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }

                static::audit($table, $id, $event, $diff, $batchId);

                return true;
            });
        } catch (Throwable $e) {
            static::audit($table, $id, "{$event}.failed", [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'error_class' => get_class($e),
                'data' => $data,
                'old_data' => $old,
                'trace' => $e->getTraceAsString(),
            ], $batchId);

            throw $e;
        } finally {
            AuditBatch::forget();
        }
    }

    /**
     * Remove um registro em $tableKey (soft delete por padrão, purge para
     * exclusão física) e audita, incluindo um snapshot do registro antigo.
     */
    protected static function auditedDelete(
        string $tableKey,
        int $id,
        string $event,
        bool $purge = false,
        ?string $batchId = null
    ): bool {
        $table = static::table($tableKey);
        $batchId = AuditBatch::resolve($batchId);

        $existing = DB::table($table)->find($id);

        if (!$existing) {
            static::audit($table, $id, "{$event}.failed", [
                'error' => "Registro #{$id} não encontrado em {$table}",
            ], $batchId);

            throw new \RuntimeException("Registro #{$id} não encontrado em {$table}.");
        }

        $old = (array) $existing;

        try {
            return DB::transaction(function () use ($table, $id, $old, $event, $batchId, $purge) {
                if ($purge) {
                    DB::table($table)->where('id', $id)->delete();
                } else {
                    DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);
                }

                static::audit($table, $id, $event, ['old' => $old, 'purge' => $purge], $batchId);

                return true;
            });
        } catch (Throwable $e) {
            static::audit($table, $id, "{$event}.failed", [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'error_class' => get_class($e),
                'old_data' => $old,
                'trace' => $e->getTraceAsString(),
            ], $batchId);

            throw $e;
        } finally {
            AuditBatch::forget();
        }
    }

    /**
     * Aplica LEFT JOINs de auditoria a uma query, resolvendo por evento
     * quem fez e quando fez a última ocorrência (ou, para 'created', a
     * primeira) de cada evento pedido. Pensado para telas de listagem —
     * DataTable, relatório — que precisam mostrar "criado por / em",
     * "atualizado por / em" etc. sem consulta N+1 por linha.
     *
     * Cada evento em $events vira duas colunas: '{prefixo}{evento}_at' e
     * '{prefixo}{evento}_by'. O prefixo (config authz.audit.column_prefix,
     * padrão 'audit_') existe porque 'created' e 'updated' são nomes de
     * evento aqui — sem prefixo, 'created_at'/'updated_at' colidiriam de
     * verdade com as colunas nativas de timestamp da própria tabela.
     *
     * Adaptação deliberada em relação ao AuditBuilder de referência: sem
     * formatação de data em SQL (DATE_FORMAT é específico de MySQL — a
     * formatação fica pra camada de apresentação do projeto host) e sem a
     * lógica de "primeiro + último nome" embutida em SQL (CONCAT/
     * SUBSTRING_INDEX, também específico de MySQL, e é decisão de
     * exibição, não do pacote). Retorna a coluna de label configurada
     * (authz.audit.user_label_column) como está. Isso também mantém a
     * query portável entre MySQL/Postgres/SQLite — os testes deste pacote
     * já rodam em SQLite via Testbench.
     *
     * Não aplica nenhum filtro de tenant à $query base — isso é
     * responsabilidade de quem monta ou passa $query, não deste método.
     *
     * @param  string  $tableKey  uma das chaves de authz.tables (groups, permissions, ...)
     * @param  Builder|null  $query  query existente para anexar os joins; se null, começa de DB::table($tabela)
     * @param  array|null  $events  eventos a incluir; default authz.audit.join_events
     */
    public function applyAuditJoins(string $tableKey, ?Builder $query = null, ?array $events = null): Builder
    {
        $table = static::table($tableKey);
        $auditTable = static::auditTable();
        $userTable = config('authz.tables.user', 'users');
        $userLabelColumn = config('authz.audit.user_label_column', 'name');
        $prefix = config('authz.audit.column_prefix', 'audit_');
        $events = $events ?? config('authz.audit.join_events', ['created', 'updated']);

        $query = $query ?? DB::table($table);

        foreach ($events as $event) {
            $joinAlias = "{$prefix}{$event}";
            $userAlias = "{$joinAlias}_user";
            $agg = $event === 'created' ? 'MIN' : 'MAX';

            // Por subject_id, o id da linha de auditoria que representa
            // este evento (a mais antiga para 'created', a mais recente
            // para qualquer outro).
            $latestIds = DB::table($auditTable)
                ->select('subject_id', DB::raw("{$agg}(id) as target_id"))
                ->where('subject_type', $table)
                ->where('event', $event)
                ->groupBy('subject_id');

            // Junta de volta com a linha de auditoria inteira, pra pegar
            // user_id/created_at daquele evento específico.
            $latestRows = DB::table("{$auditTable} as a")
                ->select('a.subject_id', 'a.user_id', 'a.created_at')
                ->joinSub($latestIds, 'latest', function ($join) {
                    $join->on('a.subject_id', '=', 'latest.subject_id')
                        ->on('a.id', '=', 'latest.target_id');
                })
                ->where('a.subject_type', $table)
                ->where('a.event', $event);

            $query->leftJoinSub($latestRows, $joinAlias, function ($join) use ($joinAlias, $table) {
                $join->on("{$joinAlias}.subject_id", '=', "{$table}.id");
            });

            $query->leftJoin("{$userTable} as {$userAlias}", "{$userAlias}.id", '=', "{$joinAlias}.user_id");

            $query->addSelect([
                "{$joinAlias}.created_at as {$prefix}{$event}_at",
                "{$userAlias}.{$userLabelColumn} as {$prefix}{$event}_by",
            ]);
        }

        return $query;
    }
}
