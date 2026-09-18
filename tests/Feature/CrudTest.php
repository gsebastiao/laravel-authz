<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Exceptions\AuthzException;
use Gsebastiao\LaravelAuthz\Facades\Authz;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class CrudTest extends TestCase
{
    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'u']);
    }

    #[Test]
    public function conceder_de_novo_depois_de_revogar_funciona(): void
    {
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');
        $group = Authz::createGroup('g');

        $grantId = Authz::grantPermissionToGroup($group, $perm);
        Authz::revokeGroupPermission($grantId);

        // Antes: violação da UNIQUE(group_id, permission_id).
        $this->assertSame($grantId, Authz::grantPermissionToGroup($group, $perm));
        $this->assertSame(1, DB::table('auth_permissions_groups')->whereNull('deleted_at')->count());
    }

    #[Test]
    public function conceder_duas_vezes_ao_grupo_atualiza_em_vez_de_duplicar(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');
        $group = Authz::createGroup('g');
        Authz::addUserToGroup($userId, $group);

        $id1 = Authz::grantPermissionToGroup($group, $perm);
        $id2 = Authz::grantPermissionToGroup($group, $perm, ['is_granted' => false, 'is_absolute' => true]);

        $this->assertSame($id1, $id2);
        $this->assertFalse(Authz::hasPermission('a.b', $userId));
    }

    #[Test]
    public function adicionar_ao_grupo_duas_vezes_nao_duplica(): void
    {
        $userId = $this->user();
        $group = Authz::createGroup('g');

        $this->assertSame(Authz::addUserToGroup($userId, $group), Authz::addUserToGroup($userId, $group));
        $this->assertCount(1, Authz::getUserGroups($userId));
    }

    #[Test]
    public function conceder_ao_usuario_duas_vezes_mantem_uma_regra_so(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');

        Authz::grantPermissionToUser($userId, $perm);
        Authz::grantPermissionToUser($userId, $perm, ['is_granted' => false]);

        $this->assertSame(1, DB::table('auth_permissions_users')->whereNull('deleted_at')->count());
        $this->assertFalse(Authz::hasPermission('a.b', $userId));
    }

    #[Test]
    public function update_com_campo_nao_permitido_lanca_erro_explicando(): void
    {
        $group = Authz::createGroup('g');

        try {
            Authz::updateGroup($group, ['tenant_key' => 5]);
            $this->fail('Esperava AuthzException');
        } catch (AuthzException $e) {
            $this->assertStringContainsString('tenant_key', $e->getMessage());
            $this->assertStringContainsString('name, description, status', $e->getMessage());
        }
    }

    #[Test]
    public function update_sem_mudanca_retorna_false(): void
    {
        $group = Authz::createGroup('g', 'desc');

        $this->assertFalse(Authz::updateGroup($group, ['description' => 'desc']));
        $this->assertTrue(Authz::updateGroup($group, ['description' => 'nova']));
    }

    #[Test]
    public function apagar_grupo_de_vez_funciona_mesmo_com_membros(): void
    {
        $userId = $this->user();
        $group = Authz::createGroup('g');
        Authz::addUserToGroup($userId, $group);

        $this->assertTrue(Authz::deleteGroup($group, purge: true));
        $this->assertDatabaseMissing('auth_groups', ['id' => $group]);
        $this->assertDatabaseMissing('auth_groups_users', ['group_id' => $group]);
    }

    #[Test]
    public function recriar_grupo_ou_permissao_apagados_explica_como_recuperar(): void
    {
        $group = Authz::createGroup('g');
        Authz::deleteGroup($group);

        try {
            Authz::createGroup('g');
            $this->fail('Esperava AuthzException');
        } catch (AuthzException $e) {
            $this->assertStringContainsString("restoreGroup({$group})", $e->getMessage());
        }

        $this->assertTrue(Authz::restoreGroup($group));
        $this->assertDatabaseHas('auth_groups', ['id' => $group, 'deleted_at' => null]);
    }

    #[Test]
    public function apagar_usuario_nao_e_bloqueado_pelas_tabelas_do_pacote(): void
    {
        $userId = $this->user();
        $group = Authz::createGroup('g');
        Authz::addUserToGroup($userId, $group);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertDatabaseMissing('auth_groups_users', ['user_id' => $userId]);
    }

    #[Test]
    public function mudar_o_catalogo_limpa_o_cache_de_todos(): void
    {
        config(['authz.cache.enabled' => true]);
        $userId = $this->user();
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');
        Authz::grantPermissionToUser($userId, $perm);
        $this->assertTrue(Authz::hasPermission('a.b', $userId));

        Authz::updatePermission($perm, ['status' => 0]);
        $this->assertFalse(Authz::hasPermission('a.b', $userId));

        Authz::updatePermission($perm, ['status' => 1]);
        Authz::deletePermission($perm);
        $this->assertFalse(Authz::hasPermission('a.b', $userId));
    }

    #[Test]
    public function purge_de_grupo_limpa_o_cache_dos_ex_membros(): void
    {
        config(['authz.cache.enabled' => true]);
        $userId = $this->user();
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');
        $group = Authz::createGroup('g');
        Authz::addUserToGroup($userId, $group);
        Authz::grantPermissionToGroup($group, $perm);
        $this->assertTrue(Authz::hasPermission('a.b', $userId));

        Authz::deleteGroup($group, purge: true);

        $this->assertFalse(Authz::hasPermission('a.b', $userId));
    }

    #[Test]
    public function flush_cache_reflete_mudancas_feitas_direto_no_banco(): void
    {
        config(['authz.cache.enabled' => true]);
        $userId = $this->user();
        $perm = Authz::createPermission('a.b', 'a', 'b', 'AB');
        $this->assertFalse(Authz::hasPermission('a.b', $userId));

        DB::table('auth_permissions_users')->insert([
            'user_id' => $userId, 'permission_id' => $perm, 'is_granted' => 1,
            'start_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse(Authz::hasPermission('a.b', $userId)); // ainda em cache

        $this->artisan('authz:cache-reset')->assertSuccessful();
        $this->assertTrue(Authz::hasPermission('a.b', $userId));
    }
}
