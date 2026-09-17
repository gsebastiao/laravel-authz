<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionGrantedToGroup
{
    public function __construct(
        public int $grantId,
        public int $groupId,
        public int $permissionId,
        public array $data
    ) {}
}
