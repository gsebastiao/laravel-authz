<?php

namespace Gsebastiao\LaravelAuthz;

use Gsebastiao\LaravelAuthz\Console\Commands\CacheResetCommand;
use Gsebastiao\LaravelAuthz\Console\Commands\InstallCommand;
use Gsebastiao\LaravelAuthz\Console\Commands\SyncPermissionsCommand;
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Http\Middleware\PermissionMiddleware;
use Gsebastiao\LaravelAuthz\Http\Middleware\RoleMiddleware;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Support\NullTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class LaravelAuthzServiceProvider extends ServiceProvider
{
    /** Migrations publicadas, na ordem em que precisam rodar. */
    public const MIGRATIONS = [
        'create_authz_groups_tables',
        'create_authz_permissions_tables',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/authz.php', 'authz');

        $this->app->bind(TenantContext::class, function ($app) {
            return $app->make(config('authz.tenant_context') ?: NullTenantContext::class);
        });

        $this->app->singleton(Authorization::class);
        $this->app->alias(Authorization::class, 'authz');
    }

    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->registerMiddlewareAliases();

        Builder::macro('whereHasPermission', function (string $permission, string $userIdColumn = 'id') {
            /** @var Builder $this */
            return app(Authorization::class)->applyWhereHasPermission($this, $permission, $userIdColumn);
        });

        // Liga as permissões ao Gate do Laravel (@can, Gate::allows...).
        // Não consulta o banco aqui — só quando uma checagem acontece.
        if (config('authz.gates.auto_register', true)) {
            $this->app->make(Authorization::class)->registerGates();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SyncPermissionsCommand::class,
                CacheResetCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/authz.php' => config_path('authz.php'),
            ], 'authz-config');

            $this->publishesMigrations($this->migrationsToPublish(), 'authz-migrations');
        }
    }

    /**
     * Mapeia cada .php.stub para um arquivo .php com timestamp. Se a
     * migration já tiver sido publicada antes, reaproveita o mesmo arquivo
     * (assim publicar de novo não cria migrations duplicadas).
     */
    protected function migrationsToPublish(): array
    {
        $paths = [];

        foreach (self::MIGRATIONS as $index => $name) {
            $existing = glob(database_path("migrations/*_{$name}.php")) ?: [];

            $paths[__DIR__ . "/../database/migrations/{$name}.php.stub"] = $existing[0]
                ?? database_path(sprintf('migrations/2026_01_01_%06d_%s.php', $index + 1, $name));
        }

        return $paths;
    }

    /**
     * @hasPermission / @hasAnyPermission / @hasAllPermissions
     * @hasRole / @hasAnyRole — cada um com o @end... correspondente.
     */
    protected function registerBladeDirectives(): void
    {
        $service = '\\' . Authorization::class;

        foreach (['hasPermission', 'hasAnyPermission', 'hasAllPermissions', 'hasRole', 'hasAnyRole'] as $method) {
            Blade::directive($method, fn($expression) => "<?php if (app({$service}::class)->{$method}({$expression})): ?>");
            Blade::directive('end' . ucfirst($method), fn() => '<?php endif; ?>');
        }
    }

    /**
     * Route::middleware('authz.permission:financeiro.aprovar')
     * Route::middleware('authz.role:financeiro')
     */
    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('authz.permission', PermissionMiddleware::class);
        $router->aliasMiddleware('authz.role', RoleMiddleware::class);
    }
}
