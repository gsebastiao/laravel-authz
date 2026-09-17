<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionRevokedFromUser
{
    public function __construct(
        public int $overrideId,
        public int $userId,
        public int $permissionId,
        public bool $purged
    ) {}
}
