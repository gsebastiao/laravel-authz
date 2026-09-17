<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionGrantedToUser
{
    public function __construct(
        public int $overrideId,
        public int $userId,
        public int $permissionId,
        public array $data
    ) {}
}
