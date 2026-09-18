<?php

namespace Gsebastiao\LaravelAuthz\Jobs;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pré-carrega o cache de um usuário em segundo plano (só faz sentido com
 * AUTHZ_CACHE_ENABLED=true). Ex: WarmCacheForUser::dispatch($user->id);
 */
class WarmCacheForUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $userId)
    {
    }

    public function handle(Authorization $authz): void
    {
        $authz->getUserGroups($this->userId);
        $authz->getEffectivePermissions($this->userId);
    }
}
