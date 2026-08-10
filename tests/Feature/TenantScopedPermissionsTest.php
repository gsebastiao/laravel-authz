<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TenantScopedPermissionsTest extends TestCase
{
    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'fixture']);
    }

    private function permission(string $name, ?int $tenantId = null): int
    {
        return DB::table('auth_permissions')->insertGetId([
            'permission' => $name,
            'module' => 'test',
            'action' => 'test',
            'label' => $name,
            'status' => 1,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function groupInTenant(string $name, ?int $tenantId): int
    {
        return DB::table('auth_groups')->insertGetId([
            'name' => $name,
            'status' => 1,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addToGroup(int $userId, int $groupId): void
    {
        DB::table('auth_groups_users')->insert([
            'user_id' => $userId,
            'group_id' => $groupId,
            'status' => 1,
            'data_inicio' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantToGroup(int $groupId, int $permissionId): void
    {
        DB::table('auth_permissions_groups')->insert([
            'group_id' => $groupId,
            'permission_id' => $permissionId,
            'is_granted' => true,
            'is_absolute' => false,
            'data_inicio' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsTenant(int|string|null $tenantId): void
    {
        $this->app->bind(TenantContext::class, fn () => new class($tenantId) implements TenantContext {
            public function __construct(private int|string|null $tenantId) {}

            public function id(): int|string|null
            {
                return $this->tenantId;
            }
        });
    }

    /** @test */
    public function sem_tenant_ativo_getAssignablePermissions_retorna_tudo(): void
    {
        $this->permission('global.padrao', tenantId: null);
        $this->permission('exportar.massa', tenantId: 1);

        $result = (new Authorization())->getAssignablePermissions(PermissionFormat::Permission);

        $this->assertEqualsCanonicalizing(['global.padrao', 'exportar.massa'], $result);
    }

    /** @test */
    public function com_tenant_ativo_getAssignablePermissions_esconde_exclusiva_de_outro_tenant(): void
    {
        $this->permission('global.padrao', tenantId: null);
        $this->permission('exportar.massa', tenantId: 1); // exclusiva do tenant 1

        $this->actingAsTenant(2); // gestor logado é do tenant 2

        $result = (new Authorization())->getAssignablePermissions(PermissionFormat::Permission);

        $this->assertSame(['global.padrao'], $result);
    }

    /** @test */
    public function tenant_dono_ve_sua_propria_permissao_exclusiva_no_seletor(): void
    {
        $this->permission('global.padrao', tenantId: null);
        $exportarMassaId = $this->permission('exportar.massa', tenantId: 1);

        $this->actingAsTenant(1);

        $result = (new Authorization())->getAssignablePermissions(PermissionFormat::Id);

        $this->assertContains($exportarMassaId, $result);
    }

    /** @test */
    public function grant_indevido_a_permissao_de_outro_tenant_e_ignorado_na_checagem(): void
    {
        // Simula o que estamos protegendo: mesmo que um grant exista
        // ligando um grupo à permissão de outro tenant — bug, edição
        // direta no banco, bypass da tela de gestão — a checagem de
        // efetivo não deve honrar isso.
        $exportarMassaId = $this->permission('exportar.massa', tenantId: 1); // exclusiva do tenant 1

        $grupoTenant2 = $this->groupInTenant('financeiro', tenantId: 2);
        $userId = $this->user();
        $this->addToGroup($userId, $grupoTenant2);

        // Grant indevido: grupo do tenant 2 recebendo permissão exclusiva do tenant 1.
        $this->grantToGroup($grupoTenant2, $exportarMassaId);

        $this->actingAsTenant(2);

        $this->assertFalse((new Authorization())->hasPermission('exportar.massa', $userId));
    }

    /** @test */
    public function grant_legitimo_de_permissao_exclusiva_do_proprio_tenant_funciona(): void
    {
        $exportarMassaId = $this->permission('exportar.massa', tenantId: 1);

        $grupoTenant1 = $this->groupInTenant('financeiro', tenantId: 1);
        $userId = $this->user();
        $this->addToGroup($userId, $grupoTenant1);
        $this->grantToGroup($grupoTenant1, $exportarMassaId);

        $this->actingAsTenant(1);

        $this->assertTrue((new Authorization())->hasPermission('exportar.massa', $userId));
    }

    /** @test */
    public function grantPermissionToGroup_rejeita_conceder_permissao_de_outro_tenant(): void
    {
        // Fecha o mesmo buraco que a checagem de leitura fecha, mas na
        // escrita: grantPermissionToGroup() nunca deveria deixar essa
        // linha ser criada, não só ignorá-la depois de já existir.
        $exportarMassaId = $this->permission('exportar.massa', tenantId: 1);
        $grupoTenant2 = $this->groupInTenant('financeiro', tenantId: 2);

        $this->expectException(\RuntimeException::class);

        (new Authorization())->grantPermissionToGroup($grupoTenant2, $exportarMassaId);
    }

    /** @test */
    public function grantPermissionToGroup_permite_conceder_permissao_global_a_qualquer_grupo(): void
    {
        $globalId = $this->permission('global.padrao', tenantId: null);
        $grupoTenant2 = $this->groupInTenant('financeiro', tenantId: 2);

        $grantId = (new Authorization())->grantPermissionToGroup($grupoTenant2, $globalId);

        $this->assertDatabaseHas('auth_permissions_groups', ['id' => $grantId, 'group_id' => $grupoTenant2]);
    }
}
