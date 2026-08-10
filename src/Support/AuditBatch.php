<?php

namespace Gsebastiao\LaravelAuthz\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rastreia o batch id de auditoria compartilhado entre todas as operações
 * de escrita deste pacote enquanto uma DB::transaction() está ativa —
 * permite agrupar auditoria de operações que tocam várias tabelas na mesma
 * operação de negócio (ex: criar um grupo e já conceder permissões a ele
 * na mesma chamada do projeto host).
 *
 * Porte do padrão usado em BaseModel (fornecido como referência) — mesma
 * lógica de resolução ciente do nível de transação:
 * - Um $explicit passado sempre vence.
 * - Sem transação ativa: um UUID novo por chamada (comportamento isolado).
 * - Com transação ativa: gera um UUID na primeira chamada dentro dela e
 *   reusa nas seguintes, até a transação mais externa terminar.
 */
class AuditBatch
{
    private static ?string $batchId = null;
    private static int $createdAtLevel = 0;

    public static function resolve(?string $explicit = null): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $level = DB::transactionLevel();

        if ($level <= 0) {
            return (string) Str::uuid();
        }

        // Se o batch registrado veio de um nível de transação que já não
        // existe mais (a transação que o originou já deu commit/rollback),
        // é obsoleto.
        if (self::$batchId !== null && $level < self::$createdAtLevel) {
            self::$batchId = null;
        }

        if (self::$batchId === null) {
            self::$batchId = (string) Str::uuid();
            self::$createdAtLevel = $level;
        }

        return self::$batchId;
    }

    /**
     * Limpa o batch ativo. Só tem efeito de fato quando não há mais
     * transação ativa (nível 0) — evita que a próxima operação reuse por
     * engano um batch de uma transação já finalizada.
     */
    public static function forget(): void
    {
        if (DB::transactionLevel() <= 0) {
            self::$batchId = null;
            self::$createdAtLevel = 0;
        }
    }
}
