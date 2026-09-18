<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

/**
 * Fallback vazio, usado quando gsebastiao/laravel-auditable não está
 * instalado. (A decisão de gravar ou não auditoria nos métodos de CRUD é
 * feita em tempo de execução por AuditsCrud, via authz.audit.enabled.)
 *
 * Resolvido como alias de ResolvedAuditableTrait por AuditableModelSupport — ver esse arquivo para o mecanismo completo.
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
