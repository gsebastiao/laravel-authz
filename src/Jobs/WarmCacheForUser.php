<?php

namespace Gsebastiao\LaravelAuthz\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Gsebastiao\LaravelAuthz\Models\Authorization;

class WarmCacheForUser implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $userId) {}

    public function handle()
    {
        $auth = new Authorization();
        $auth->getEffectivePermissions($this->userId);
        $auth->getUserGroups($this->userId);
    }
}