<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache de getUserGroups() e getEffectivePermissions().
 *
 * Invalidação por "versão": cada chave de cache inclui um token global e
 * um token por usuário. Invalidar = trocar o token. As entradas antigas
 * simplesmente deixam de ser lidas e expiram sozinhas pelo TTL.
 *
 * Funciona com qualquer driver do Laravel (file, database, redis,
 * memcached, array...) e continua correto mesmo se o driver descartar
 * alguma chave por falta de memória.
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
        return (string) config('authz.cache.prefix', 'authz');
    }

    protected static function invalidateOnWrite(): bool
    {
        return (bool) config('authz.cache.invalidate_on_write', true);
    }

    /**
     * Lê do cache ou calcula. Com o cache desligado (padrão), sempre calcula.
     */
    protected static function rememberForUser(int $userId, string $type, \Closure $compute): mixed
    {
        if (!static::cacheEnabled()) {
            return $compute();
        }

        $store = static::cacheStore();
        $key = implode(':', [
            static::cachePrefix(),
            $type,
            'user', $userId,
            'tenant', static::activeTenantId() ?? 'global',
            'v', static::versionToken($store, static::globalVersionKey()) . '.' . static::versionToken($store, static::userVersionKey($userId)),
        ]);

        return $store->remember($key, static::cacheTtl(), $compute);
    }

    protected static function globalVersionKey(): string
    {
        return static::cachePrefix() . ':version:global';
    }

    protected static function userVersionKey(int $userId): string
    {
        return static::cachePrefix() . ':version:user:' . $userId;
    }

    protected static function versionToken(Repository $store, string $key): string
    {
        $token = $store->get($key);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(6));
            $store->forever($key, $token);
        }

        return $token;
    }

    /**
     * Esquece o cache de UM usuário. Pode ser chamado a qualquer momento,
     * inclusive com o cache desligado.
     */
    public static function forgetUserCache(int $userId): void
    {
        static::cacheStore()->forget(static::userVersionKey($userId));
    }

    /**
     * Esquece o cache de TODOS os usuários. Use depois de mexer nas
     * tabelas do pacote direto no banco (seeders, imports, SQL manual).
     * Também disponível como: php artisan authz:cache-reset
     */
    public static function flushCache(): void
    {
        static::cacheStore()->forget(static::globalVersionKey());
    }

    protected static function invalidateForUser(int $userId): void
    {
        if (static::invalidateOnWrite()) {
            static::forgetUserCache($userId);
        }
    }

    /**
     * @param  array<int>|null  $userIds  membros já conhecidos (usado quando o
     *                                   grupo é apagado e não dá mais para consultar)
     */
    protected static function invalidateForGroup(int $groupId, ?array $userIds = null): void
    {
        if (!static::invalidateOnWrite()) {
            return;
        }

        foreach ($userIds ?? static::groupMemberIds($groupId) as $userId) {
            static::forgetUserCache((int) $userId);
        }
    }

    protected static function invalidateEveryone(): void
    {
        if (static::invalidateOnWrite()) {
            static::flushCache();
        }
    }

    /** @return array<int> */
    protected static function groupMemberIds(int $groupId): array
    {
        return DB::table(static::table('groups_users'))
            ->where('group_id', $groupId)
            ->distinct()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }
}
