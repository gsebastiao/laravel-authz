<?php

namespace Gsebastiao\LaravelAuthz\Tests;

use Gsebastiao\LaravelAuthz\LaravelAuthzServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAuthzServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite não aplica foreign keys por padrão — sem isso, um
            // teste que depende de uma FK apontar pra tabela errada
            // passaria mesmo com o bug presente, porque a violação nunca
            // seria checada de verdade.
            'foreign_key_constraints' => true,
        ]);

        // Explícito em vez de confiar no default do Testbench — 'array'
        // é em memória, isolado por teste, sem dependência externa.
        // Define o store inteiro, não só 'default', pelo mesmo motivo que
        // a conexão de banco acima é explícita: não presumir que o
        // Testbench já populou isso sozinho.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Tabela mínima de users — o pacote assume que ela já existe no
        // projeto host, não é responsabilidade dele criá-la. Lê o nome
        // via config para que testes possam simular um projeto que já
        // renomeou essa tabela (ex: 'usuarios').
        Schema::create(config('authz.tables.users', 'users'), function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
