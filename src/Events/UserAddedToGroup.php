<?php

namespace Gsebastiao\LaravelAuthz\Events;

class UserAddedToGroup
{
    public function __construct(
        public int $membershipId,
        public int $userId,
        public int $groupId,
        public array $data
    ) {}
}
