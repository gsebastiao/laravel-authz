<?php

namespace Gsebastiao\LaravelAuthz\Exceptions;

/**
 * Erro "de uso" do pacote: registro não encontrado, nome duplicado,
 * campo que não pode ser alterado, etc. A mensagem sempre explica
 * o que aconteceu e o que fazer.
 *
 * Estende \RuntimeException, então quem já fazia catch de
 * \RuntimeException continua funcionando.
 */
class AuthzException extends \RuntimeException
{
}
