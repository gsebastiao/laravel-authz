<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionRevokedFromGroup
{
    public function __construct(
        public int $grantId,
        public int $groupId,
        public int $permissionId,
        public bool $purged
    ) {}
}
