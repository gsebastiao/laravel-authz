<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

/**
 * Fallback vazio, usado quando gsebastiao/laravel-auditable não está
 * instalado (ou está instalado mas config('authz.audit.enabled') é
 * false). Resolvido como alias de ResolvedAuditableTrait por
 * AuditableModelSupport — ver esse arquivo para o mecanismo completo.
 *
 * Não define nada de propósito: os 5 models do pacote usam
 * `use ResolvedAuditableTrait;` incondicionalmente, então este trait só
 * precisa existir para o class_alias() apontar para algo inofensivo
 * quando a auditoria real não está disponível.
 */
trait NoOpAuditable
{
    //
}
