<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigurableTableNamesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Simula um projeto que já usa 'auth_groups' para outra coisa
        // (ex: outro pacote de permissões) e precisa renomear antes de
        // rodar as migrations publicadas.
        $app['config']->set('authz.tables.groups', 'authz_groups_renomeado');
        $app['config']->set('authz.tables.groups_users', 'authz_groups_users_renomeado');
    }

    /** @test */
    public function migrations_publicadas_respeitam_nomes_de_tabela_customizados(): void
    {
        $this->assertTrue(Schema::hasTable('authz_groups_renomeado'));
        $this->assertFalse(Schema::hasTable('auth_groups'));
    }

    /** @test */
    public function model_le_e_escreve_no_nome_de_tabela_customizado(): void
    {
        $userId = DB::table('users')->insertGetId(['name' => 'fixture']);

        $groupId = DB::table('authz_groups_renomeado')->insertGetId([
            'name' => 'financeiro',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('authz_groups_users_renomeado')->insert([
            'user_id' => $userId,
            'group_id' => $groupId,
            'status' => 1,
            'data_inicio' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, (new Authorization())->getUserGroups($userId));
    }
}

class ConfigurableUserTableTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Simula um projeto onde a tabela de usuários já se chama
        // 'usuarios' — TestCase::defineDatabaseMigrations() lê esse
        // mesmo config pra criar a tabela com o nome certo.
        $app['config']->set('authz.tables.user', 'usuarios');
    }

    /** @test */
    public function foreign_key_de_user_id_aponta_para_a_tabela_configurada(): void
    {
        $this->assertTrue(Schema::hasTable('usuarios'));
        $this->assertFalse(Schema::hasTable('users'));

        $userId = DB::table('usuarios')->insertGetId(['name' => 'fixture']);
        $groupId = DB::table('auth_groups')->insertGetId([
            'name' => 'financeiro',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Se a FK de auth_groups_users.user_id apontasse para 'users'
        // (hardcoded) em vez de 'usuarios' (config), este insert falharia
        // por violação de foreign key.
        DB::table('auth_groups_users')->insert([
            'user_id' => $userId,
            'group_id' => $groupId,
            'status' => 1,
            'data_inicio' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, (new Authorization())->getUserGroups($userId));
    }
}
