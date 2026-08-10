<?php

namespace Gsebastiao\LaravelAuthz;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Support\NullTenantContext;
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
    }

    public function boot(): void
    {
        // Fora do runningInConsole() de propósito: precisa estar
        // disponível também quando o Testbench roda migrations durante
        // os testes, não só via artisan em CLI.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/authz.php' => config_path('authz.php'),
            ], 'authz-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'authz-migrations');
        }
    }
}
