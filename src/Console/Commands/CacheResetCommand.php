<?php

namespace Gsebastiao\LaravelAuthz\Console\Commands;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Console\Command;

/**
 * php artisan authz:cache-reset
 *
 * Use depois de alterar as tabelas do pacote diretamente no banco
 * (seeders, SQL manual, importações). Só tem efeito com o cache ligado.
 */
class CacheResetCommand extends Command
{
    protected $signature = 'authz:cache-reset';

    protected $description = 'Limpa o cache de grupos e permissões de todos os usuários';

    public function handle(): int
    {
        Authorization::flushCache();
        $this->components->info('Cache do laravel-authz limpo.');

        return self::SUCCESS;
    }
}
