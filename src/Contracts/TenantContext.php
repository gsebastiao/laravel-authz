<?php

namespace Gsebastiao\LaravelAuthz\Contracts;

interface TenantContext
{
    /**
     * Retorna o identificador do tenant ativo, ou null se o projeto
     * estiver operando em modo single-tenant (comportamento padrão).
     *
     * Implementações do projeto host decidem de onde esse id vem —
     * sessão, subdomínio, header, claim de token, etc. O pacote nunca
     * assume a origem.
     */
    public function id(): int|string|null;
}
