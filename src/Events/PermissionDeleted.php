<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionDeleted
{
    public function __construct(
        public int $permissionId,
        public array $oldData,
        public bool $purged
    ) {}
}
