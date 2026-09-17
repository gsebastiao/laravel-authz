<?php

namespace Gsebastiao\LaravelAuthz\Events;

class GroupDeleted
{
    public function __construct(
        public int $groupId,
        public array $oldData,
        public bool $purged
    ) {}
}
