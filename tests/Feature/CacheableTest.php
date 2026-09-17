<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class CacheableTest extends TestCase
{
    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'fixture']);
    }

    private function permission(string $name): int
    {
        return DB::table('auth_permissions')->insertGetId([
            'permission' => $name,
            'module' => 'test',
            'action' => 'test',
            'label' => $name,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantDirectly(int $userId, int $permissionId): void
    {
        // Insert cru, contornando o CRUD do pacote de propósito — simula
        // uma mudança que a invalidação automática nunca veria, provando
        // que uma resposta repetida está vindo do cache, não do banco.
        DB::table('auth_permissions_users')->insert([
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'is_granted' => true,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function com_cache_desligado_padrao_mudanca_direta_no_banco_aparece_na_hora(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));

        $this->grantDirectly($userId, $permId);

        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    /** @test */
    public function com_cache_ligado_segunda_chamada_nao_reflete_mudanca_direta_no_banco(): void
    {
        config(['authz.cache.enabled' => true]);

        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));

        $this->grantDirectly($userId, $permId);

        // A resposta da primeira chamada (negado) fica servida — a
        // mudança direta no banco não passou pela invalidação do pacote.
        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    /** @test */
    public function cache_e_isolado_por_tenant_ativo(): void
    {
        config(['authz.cache.enabled' => true]);

        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantDirectly($userId, $permId);

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null
            {
                return 1;
            }
        });

        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null
            {
                return 2;
            }
        });

        // Chave de cache diferente (tenant 2) — calcula fresco de novo,
        // não herda a entrada do tenant 1. Mesma resposta aqui porque a
        // permissão é global neste teste, mas prova que são entradas
        // independentes, não uma vazando pra outra.
        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    /** @test */
    public function grantPermissionToUser_invalida_o_cache_do_proprio_usuario(): void
    {
        config(['authz.cache.enabled' => true]);

        $auth = new Authorization();
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');

        $this->assertFalse($auth->hasPermission('financeiro.aprovar', $userId));

        $auth->grantPermissionToUser($userId, $permId);

        // Passou pelo CRUD do pacote, que invalida automaticamente —
        // reflete na hora, mesmo com cache ligado.
        $this->assertTrue($auth->hasPermission('financeiro.aprovar', $userId));
    }

    /** @test */
    public function grantPermissionToGroup_invalida_o_cache_de_todo_membro_do_grupo(): void
    {
        config(['authz.cache.enabled' => true]);

        $auth = new Authorization();
        $permId = $this->permission('financeiro.aprovar');
        $groupId = $auth->createGroup('financeiro');

        $user1 = $this->user();
        $user2 = $this->user();
        $auth->addUserToGroup($user1, $groupId);
        $auth->addUserToGroup($user2, $groupId);

        // Aquece o cache dos dois com "negado" antes da concessão.
        $this->assertFalse($auth->hasPermission('financeiro.aprovar', $user1));
        $this->assertFalse($auth->hasPermission('financeiro.aprovar', $user2));

        $auth->grantPermissionToGroup($groupId, $permId);

        // A concessão foi no GRUPO, não em nenhum usuário individualmente
        // — os dois membros precisam refletir a mudança, não só quem
        // chamou grantPermissionToGroup().
        $this->assertTrue($auth->hasPermission('financeiro.aprovar', $user1));
        $this->assertTrue($auth->hasPermission('financeiro.aprovar', $user2));
    }

    /** @test */
    public function invalidate_on_write_desligado_mantem_resposta_antiga_ate_forget_manual(): void
    {
        config([
            'authz.cache.enabled' => true,
            'authz.cache.invalidate_on_write' => false,
        ]);

        $auth = new Authorization();
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');

        $this->assertFalse($auth->hasPermission('financeiro.aprovar', $userId));

        $auth->grantPermissionToUser($userId, $permId);

        // invalidate_on_write desligado -> a concessão não dispara
        // invalidação automática, resposta antiga continua servida.
        $this->assertFalse($auth->hasPermission('financeiro.aprovar', $userId));

        Authorization::forgetUserCache($userId);

        // forgetUserCache() continua funcionando manualmente mesmo com
        // invalidate_on_write desligado.
        $this->assertTrue($auth->hasPermission('financeiro.aprovar', $userId));
    }

    /** @test */
    public function formatos_diferentes_compartilham_a_mesma_entrada_de_cache(): void
    {
        config(['authz.cache.enabled' => true]);

        $auth = new Authorization();
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $auth->grantPermissionToUser($userId, $permId);

        $ids = $auth->getEffectivePermissions($userId, PermissionFormat::Id);
        $this->assertSame([$permId], $ids);

        // Muda o banco direto, contornando o CRUD do pacote. Se cada
        // formato tivesse sua própria entrada de cache, este segundo
        // formato (ainda não pedido) calcularia fresco e veria a
        // mudança. Não deveria ver — compartilham a mesma entrada
        // cacheada desde a primeira chamada.
        $permId2 = $this->permission('financeiro.rejeitar');
        $this->grantDirectly($userId, $permId2);

        $names = $auth->getEffectivePermissions($userId, PermissionFormat::Permission);
        $this->assertSame(['financeiro.aprovar'], $names);
    }

    /** @test */
    public function getUserGroups_tambem_e_cacheado_independentemente(): void
    {
        config(['authz.cache.enabled' => true]);

        $auth = new Authorization();
        $userId = $this->user();
        $groupId = $auth->createGroup('financeiro');
        $auth->addUserToGroup($userId, $groupId);

        $this->assertCount(1, $auth->getUserGroups($userId));

        // Segundo grupo associado direto no banco, contornando o CRUD do
        // pacote — não deveria aparecer, porque getUserGroups() já está
        // servindo do cache.
        $groupId2 = DB::table('auth_groups')->insertGetId([
            'name' => 'rh',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('auth_groups_users')->insert([
            'user_id' => $userId,
            'group_id' => $groupId2,
            'status' => 1,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, $auth->getUserGroups($userId));
    }
}
