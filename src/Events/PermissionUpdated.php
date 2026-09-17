<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionUpdated
{
    public function __construct(
        public int $permissionId,
        public array $oldData,
        public array $newData
    ) {}
}
