<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache-aside para getUserGroups()/getEffectivePermissions(), com
 * invalidação por registro de chaves em vez de tags ou busca por padrão —
 * funciona identicamente em file, database, redis, memcached, array, ou
 * qualquer outro driver que o Cache facade do Laravel suporte, porque
 * nunca fala com um driver específico diretamente.
 *
 * Cada put() nesta trait também grava a própria chave numa lista por
 * usuário ('registro'). forgetUserCache() lê essa lista e apaga cada
 * chave dela, depois apaga a própria lista — é assim que a invalidação
 * funciona em drivers que não suportam tags nem KEYS/SCAN (file,
 * database), sem precisar de um comando específico de driver nenhum.
 */
trait Cacheable
{
    protected static function cacheEnabled(): bool
    {
        return (bool) config('authz.cache.enabled', false);
    }

    protected static function cacheStore(): Repository
    {
        $store = config('authz.cache.store');

        return $store ? Cache::store($store) : Cache::store();
    }

    protected static function cacheTtl(): int
    {
        return (int) config('authz.cache.ttl', 3600);
    }

    protected static function cachePrefix(): string
    {
        return config('authz.cache.prefix', 'authz');
    }

    protected static function invalidateOnWrite(): bool
    {
        return (bool) config('authz.cache.invalidate_on_write', true);
    }

    protected static function permissionsCacheKey(int $userId): string
    {
        return static::cachePrefix() . ':permissions:user:' . $userId . ':tenant:' . (self::activeTenantId() ?? 'global');
    }

    protected static function groupsCacheKey(int $userId): string
    {
        return static::cachePrefix() . ':groups:user:' . $userId . ':tenant:' . (self::activeTenantId() ?? 'global');
    }

    protected static function registryKey(int $userId): string
    {
        return static::cachePrefix() . ':registry:user:' . $userId;
    }

    /**
     * Cache-aside genérico: retorna do cache se existir; senão, calcula
     * via $compute, guarda, registra a chave para invalidação futura, e
     * retorna. Com o cache desligado (padrão), $compute() roda sempre,
     * sem nenhum outro efeito colateral — comportamento idêntico a antes
     * de o cache existir.
     */
    protected static function rememberForUser(int $userId, string $key, \Closure $compute): mixed
    {
        if (!static::cacheEnabled()) {
            return $compute();
        }

        $store = static::cacheStore();

        if ($store->has($key)) {
            return $store->get($key);
        }

        $value = $compute();

        $store->put($key, $value, static::cacheTtl());
        static::registerCacheKey($store, $userId, $key);

        return $value;
    }

    protected static function registerCacheKey(Repository $store, int $userId, string $key): void
    {
        $registryKey = static::registryKey($userId);
        $keys = $store->get($registryKey, []);

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $store->put($registryKey, $keys, static::cacheTtl());
        }
    }

    /**
     * Invalida todo cache deste pacote para um usuário específico.
     * Funciona independente de config('authz.cache.enabled') — se algo
     * ficou em cache enquanto estava ligado, isto ainda precisa conseguir
     * limpar mesmo que o cache tenha sido desligado depois. Chamável
     * manualmente a qualquer momento, além de usado internamente pelas
     * funções de CRUD (ver invalidateForUser()/invalidateForGroup()).
     */
    public static function forgetUserCache(int $userId): void
    {
        $store = static::cacheStore();
        $registryKey = static::registryKey($userId);
        $keys = $store->get($registryKey, []);

        foreach ($keys as $key) {
            $store->forget($key);
        }

        $store->forget($registryKey);
    }

    /**
     * Invalida o cache de todo membro ativo de um grupo — usado quando
     * uma concessão do PRÓPRIO grupo muda (afeta todo membro dele, não só
     * quem fez a mudança). Não tenta ser mais esperto que isso: consulta
     * quem é membro agora e invalida cada um, sem tags nem heurística.
     */
    protected static function forgetGroupMembersCache(int $groupId): void
    {
        $userIds = DB::table(static::table('groups_users'))
            ->where('group_id', $groupId)
            ->whereNull('deleted_at')
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            static::forgetUserCache($userId);
        }
    }

    /**
     * Chamado pelas funções de CRUD que afetam só um usuário. Respeita
     * authz.cache.invalidate_on_write — forgetUserCache() em si continua
     * disponível pra chamada manual mesmo com isto desligado.
     */
    protected static function invalidateForUser(int $userId): void
    {
        if (static::invalidateOnWrite()) {
            static::forgetUserCache($userId);
        }
    }

    /**
     * Chamado pelas funções de CRUD que afetam um grupo inteiro (uma
     * concessão de permissão do grupo, não uma exceção individual).
     */
    protected static function invalidateForGroup(int $groupId): void
    {
        if (static::invalidateOnWrite()) {
            static::forgetGroupMembersCache($groupId);
        }
    }
}
