<?php

namespace Gsebastiao\LaravelAuthz\Events;

class GroupUpdated
{
    public function __construct(
        public int $groupId,
        public array $oldData,
        public array $newData
    ) {}
}
