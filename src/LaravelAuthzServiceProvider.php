<?php

namespace Gsebastiao\LaravelAuthz;

use Gsebastiao\LaravelAuthz\Console\Commands\SyncPermissionsCommand;
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Http\Middleware\PermissionMiddleware;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Support\NullTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class LaravelAuthzServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/authz.php', 'authz');

        // Único lugar do pacote que decide qual implementação concreta
        // de TenantContext está ativa. Authorization::activeTenantId()
        // só resolve a interface — nunca lê config diretamente.
        $this->app->bind(TenantContext::class, function ($app) {
            $concrete = config('authz.tenant_context', NullTenantContext::class);

            return $app->make($concrete);
        });

        // Bind do Authorization como singleton
        $this->app->singleton(Authorization::class, function ($app) {
            return new Authorization();
        });

        // Alias para facilitar o uso (também usado por helpers.php via authz())
        $this->app->alias(Authorization::class, 'authz');
    }

    public function boot(): void
    {
        // Nota: helpers.php e AuditableModelSupport.php já são carregados
        // via composer.json > autoload > files, antes de qualquer classe
        // do pacote — não é preciso (nem correto) fazer require aqui.

        $this->registerBladeDirectives();
        $this->registerMiddlewareAlias();
        $this->registerEloquentMacro();
        $this->registerGatesIfEnabled();

        $this->commands([
            SyncPermissionsCommand::class,
        ]);

        // Fora do runningInConsole() de propósito: precisa estar
        // disponível também quando o Testbench roda migrations durante
        // os testes, não só via artisan em CLI.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/authz.php' => config_path('authz.php'),
            ], 'authz-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'authz-migrations');
        }
    }

    /**
     * Registra as Blade directives do pacote: @hasPermission,
     * @hasAnyPermission, @hasRole e os @end* correspondentes.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('hasPermission', function ($expression) {
            return "<?php if (hasPermission({$expression})): ?>";
        });

        Blade::directive('endHasPermission', function () {
            return '<?php endif; ?>';
        });

        Blade::directive('hasAnyPermission', function ($expression) {
            return "<?php if (hasAnyPermission({$expression})): ?>";
        });

        Blade::directive('endHasAnyPermission', function () {
            return '<?php endif; ?>';
        });

        Blade::directive('hasRole', function ($expression) {
            return "<?php if (hasRole({$expression})): ?>";
        });

        Blade::directive('endHasRole', function () {
            return '<?php endif; ?>';
        });
    }

    /**
     * Registra o alias de middleware 'authz.permission' ->
     * PermissionMiddleware, para uso como `->middleware('authz.permission:financeiro.aprovar')`.
     */
    protected function registerMiddlewareAlias(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('authz.permission', PermissionMiddleware::class);
    }

    /**
     * Registra a macro Builder::macro('whereHasPermission', ...),
     * delegando para Authorization::applyWhereHasPermission() — filtro
     * de listagem sem N+1, mesma cascata de precedência de
     * hasPermission().
     */
    protected function registerEloquentMacro(): void
    {
        Builder::macro('whereHasPermission', function (string $permission, string $userIdColumn = 'id') {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            return app(Authorization::class)->applyWhereHasPermission($this, $permission, $userIdColumn);
        });
    }

    /**
     * Registra um Gate::define() por permissão do catálogo, condicional
     * a config('authz.gates.auto_register').
     */
    protected function registerGatesIfEnabled(): void
    {
        if (!config('authz.gates.auto_register', true)) {
            return;
        }

        $this->app->booted(function () {
            app(Authorization::class)->registerGates();
        });
    }
}
