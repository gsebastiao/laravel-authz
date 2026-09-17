<?php

namespace Gsebastiao\LaravelAuthz\Events;

class UserRemovedFromGroup
{
    public function __construct(
        public int $membershipId,
        public int $userId,
        public int $groupId,
        public bool $purged
    ) {}
}
