<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionCreated
{
    public function __construct(
        public int $permissionId,
        public array $data
    ) {}
}
