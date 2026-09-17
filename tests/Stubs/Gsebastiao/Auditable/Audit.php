<?php

namespace Gsebastiao\Auditable;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stub de teste de Audit — intercepta e registra cada chamada em
 * self::$calls (para asserções de "chamou com os parâmetros certos"),
 * e também persiste um log mínimo real em SQLite (tabela
 * '_test_audit_log', criada sob demanda) para que AuditColumnJoinerTest
 * possa fazer joins reais e AuditJoinsTest valide colunas de verdade,
 * sem depender do pacote laravel-auditable de fato instalado.
 */
class Audit
{
    public static array $calls = [];

    protected static function ensureLogTable(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable(self::logTableName())) {
            \Illuminate\Support\Facades\Schema::create(self::logTableName(), function ($table) {
                $table->id();
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->string('event');
                $table->text('changes')->nullable();
                $table->string('user_name')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public static function logTableName(): string
    {
        return '_test_audit_log';
    }

    public static function logQuery(): Builder
    {
        self::ensureLogTable();

        return DB::table(self::logTableName());
    }

    public static function log(string $subjectType, int|string $subjectId, string $event, array $changes = []): void
    {
        self::$calls[] = [
            'method' => 'log',
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'event' => $event,
            'changes' => $changes,
        ];

        self::ensureLogTable();

        DB::table(self::logTableName())->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'changes' => json_encode($changes),
            'user_name' => \Illuminate\Support\Facades\Auth::user()?->name,
            'created_at' => now(),
        ]);
    }

    public static function logFailure(
        string $subjectType,
        int|string $subjectId,
        string $event,
        \Throwable $exception,
        array $context = []
    ): void {
        self::$calls[] = [
            'method' => 'logFailure',
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'event' => $event,
            'exception' => $exception,
            'context' => $context,
        ];
    }

    public static function transaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    public static function trailFor(string $subjectType, int|string $subjectId): array
    {
        return array_values(array_filter(
            self::$calls,
            fn($c) => ($c['method'] ?? null) === 'log'
                && $c['subjectType'] === $subjectType
                && (string) $c['subjectId'] === (string) $subjectId
        ));
    }

    public static function reset(): void
    {
        self::$calls = [];

        if (\Illuminate\Support\Facades\Schema::hasTable(self::logTableName())) {
            DB::table(self::logTableName())->truncate();
        }
    }
}
