<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function migrations_publicadas_respeitam_nomes_de_tabela_customizados(): void
    {
        $this->assertTrue(Schema::hasTable('authz_groups_renomeado'));
        $this->assertFalse(Schema::hasTable('auth_groups'));
    }

    #[Test]
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
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, (new Authorization())->getUserGroups($userId));
    }
}
