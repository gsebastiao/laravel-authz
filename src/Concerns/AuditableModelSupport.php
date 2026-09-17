<?php

namespace Gsebastiao\LaravelAuthz\Concerns;

/**
 * Resolve, uma única vez por processo, para o que
 * `Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait` aponta:
 *
 * - Se gsebastiao/laravel-auditable estiver instalado (o trait
 *   `Gsebastiao\Auditable\Concerns\Auditable` existir), aponta para ele.
 * - Senão, aponta para `NoOpAuditable` (vazio, sempre disponível).
 *
 * Isto acontece via class_alias(), que precisa rodar ANTES de qualquer
 * classe que faça `use ResolvedAuditableTrait;` ser carregada — por
 * isso este arquivo é registrado em composer.json > autoload > files,
 * o mesmo mecanismo que carrega helpers.php, garantindo que roda antes
 * de qualquer Models\*.php via autoload PSR-4.
 *
 * Não é possível aplicar uma trait a uma classe depois dela já ter sido
 * declarada — é por isso que as tentativas anteriores (aplicar a trait
 * dinamicamente num __construct ou num boot de ServiceProvider) nunca
 * funcionavam. class_alias() resolvido cedo o suficiente é o único jeito
 * de fazer "auditoria condicional" funcionar de verdade aqui.
 *
 * IMPORTANTE: a checagem de existência do trait real usa trait_exists(),
 * nunca class_exists(). class_exists() do PHP SEMPRE retorna false para
 * traits, mesmo que o trait exista e já esteja carregado — isso só foi
 * descoberto testando por execução real; php -l nunca acusaria, porque
 * o código é sintaticamente válido dos dois jeitos, só falha
 * silenciosamente em runtime (sempre cai no ramo NoOpAuditable, mesmo
 * com o pacote real instalado).
 */

if (!class_exists(\Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait::class, false)
    && !trait_exists(\Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait::class, false)
) {
    if (trait_exists(\Gsebastiao\Auditable\Concerns\Auditable::class)) {
        class_alias(
            \Gsebastiao\Auditable\Concerns\Auditable::class,
            \Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait::class
        );
    } else {
        class_alias(
            \Gsebastiao\LaravelAuthz\Concerns\NoOpAuditable::class,
            \Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait::class
        );
    }
}
