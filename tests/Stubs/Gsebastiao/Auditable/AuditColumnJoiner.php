<?php

namespace Gsebastiao\Auditable;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stub de teste de AuditColumnJoiner, funcional o suficiente para
 * validar AuditJoinsTest sem depender do pacote real: mantém uma
 * tabela de log própria em memória (populada por Audit::log(), que
 * este stub também usa) e faz o LEFT JOIN de verdade contra ela,
 * trazendo a coluna 'user_id' do log como '{prefixo}{evento}_by' e o
 * timestamp como '{prefixo}{evento}_at'.
 */
class AuditColumnJoiner
{
    public static array $calls = [];

    public static function apply(Builder $query, string $modelClass, array $events = [], string $columnPrefix = ''): Builder
    {
        self::$calls[] = [
            'modelClass' => $modelClass,
            'events' => $events,
            'columnPrefix' => $columnPrefix,
        ];

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        foreach ($events as $event) {
            $alias = "audit_{$event}";
            $atColumn = "{$columnPrefix}{$event}_at";
            $byColumn = "{$columnPrefix}{$event}_by";

            // Junta, para cada linha, o log MAIS RECENTE daquele evento
            // (subquery de agregação, sem N+1): equivalente a "pega o
            // último update", não o primeiro.
            $query->leftJoinSub(
                Audit::logQuery()
                    ->where('subject_type', $table)
                    ->where('event', $event)
                    ->selectRaw('subject_id, MAX(id) as latest_id')
                    ->groupBy('subject_id'),
                "{$alias}_latest",
                "{$alias}_latest.subject_id",
                '=',
                "{$table}.id"
            )->leftJoin(
                Audit::logTableName() . " as {$alias}",
                "{$alias}.id",
                '=',
                "{$alias}_latest.latest_id"
            )->addSelect([
                "{$alias}.created_at as {$atColumn}",
                "{$alias}.user_name as {$byColumn}",
            ]);
        }

        return $query;
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
