<?php

namespace Gsebastiao\LaravelAuthz\Events;

class PermissionUpdatedForUser
{
    public function __construct(
        public int $overrideId,
        public array $oldData,
        public array $newData
    ) {}
}
