<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigurableUserTableTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Simula um projeto onde a tabela de usuários já se chama
        // 'usuarios' — TestCase::defineDatabaseMigrations() lê esse
        // mesmo config pra criar a tabela com o nome certo.
        $app['config']->set('authz.tables.users', 'usuarios');
    }

    #[Test]
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
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, (new Authorization())->getUserGroups($userId));
    }
}
