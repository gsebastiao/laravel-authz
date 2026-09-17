<?php

namespace Gsebastiao\LaravelAuthz\Events;

class GroupCreated
{
    public function __construct(
        public int $groupId,
        public array $data
    ) {}
}
