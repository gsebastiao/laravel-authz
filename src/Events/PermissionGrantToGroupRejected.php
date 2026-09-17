<?php

namespace Gsebastiao\LaravelAuthz\Events;

/**
 * Disparado quando grantPermissionToGroup() rejeita uma concessão porque
 * a permissão é exclusiva de outro tenant e não é visível ao grupo alvo.
 * Nada é gravado no banco quando este evento dispara.
 */
class PermissionGrantToGroupRejected
{
    public function __construct(
        public int $groupId,
        public int $permissionId,
        public ?int $permissionTenantId,
        public ?int $groupTenantId
    ) {}
}
