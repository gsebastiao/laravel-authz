<?php

namespace Gsebastiao\LaravelAuthz\Support;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;

/**
 * Binding padrão do pacote. Sempre retorna null, o que faz
 * Authorization::activeTenantId() nunca aplicar filtro de tenant_id —
 * ou seja, comportamento idêntico ao single-tenant original, sem
 * nenhuma mudança de resultado para quem não configurar nada.
 */
class NullTenantContext implements TenantContext
{
    public function id(): int|string|null
    {
        return null;
    }
}
