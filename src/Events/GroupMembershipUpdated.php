<?php

namespace Gsebastiao\LaravelAuthz\Events;

class GroupMembershipUpdated
{
    public function __construct(
        public int $membershipId,
        public array $oldData,
        public array $newData
    ) {}
}
