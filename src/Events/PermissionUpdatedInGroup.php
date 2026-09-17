<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionUpdatedInGroup
{
    public function __construct(
        public int $grantId,
        public array $oldData,
        public array $newData
    ) {}
}
