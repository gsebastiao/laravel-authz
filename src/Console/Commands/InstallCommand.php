<?php

namespace Gsebastiao\LaravelAuthz\Console\Commands;

use Illuminate\Console\Command;

/**
 * php artisan authz:install
 *
 * Publica o config e as migrations e (opcionalmente) roda o migrate.
 */
class InstallCommand extends Command
{
    protected $signature = 'authz:install {--migrate : Roda as migrations sem perguntar}';

    protected $description = 'Instala o laravel-authz: publica config e migrations';

    public function handle(): int
    {
        $this->components->info('Instalando laravel-authz...');

        $this->callSilently('vendor:publish', ['--tag' => 'authz-config']);
        $this->components->task('config/authz.php', fn() => file_exists(config_path('authz.php')));

        $this->callSilently('vendor:publish', ['--tag' => 'authz-migrations']);
        $this->components->task('migrations em database/migrations', fn() => (bool) glob(database_path('migrations/*_create_authz_*_tables.php')));

        $this->newLine();
        $this->line('  Precisa mudar o nome de alguma tabela (ex: sua tabela de usuários não se chama "users")?');
        $this->line('  Edite <comment>config/authz.php</comment> ANTES de rodar o migrate.');
        $this->newLine();

        $migrate = $this->option('migrate')
            || ($this->input->isInteractive() && $this->confirm('Rodar as migrations agora?', true));

        if ($migrate) {
            $this->call('migrate');
        } else {
            $this->components->warn('Lembre-se de rodar: php artisan migrate');
        }

        $this->newLine();
        $this->components->info('Pronto! Próximo passo: adicione `use HasAuthz;` no seu model User.');

        return self::SUCCESS;
    }
}
