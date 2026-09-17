<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TenantScopeTest extends TestCase
{
    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'fixture']);
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
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function com_null_tenant_context_padrao_ve_todos_os_grupos_do_usuario(): void
    {
        // Nenhum bind customizado nesta suite -> usa NullTenantContext,
        // o binding padrão do pacote. Prova o critério "comportamento
        // idêntico ao single-tenant original quando nada é configurado".
        $userId = $this->user();
        $groupId = $this->groupInTenant('financeiro', tenantId: null);

        $this->addToGroup($userId, $groupId);

        $this->assertCount(1, (new Authorization())->getUserGroups($userId));
    }

    /** @test */
    public function com_tenant_context_ativo_grupo_de_outro_tenant_fica_invisivel(): void
    {
        $userId = $this->user();

        $grupoTenantA = $this->groupInTenant('financeiro', tenantId: 1);
        $grupoTenantB = $this->groupInTenant('financeiro', tenantId: 2);

        $this->addToGroup($userId, $grupoTenantA);
        $this->addToGroup($userId, $grupoTenantB);

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null
            {
                return 1;
            }
        });

        $groups = (new Authorization())->getUserGroups($userId);

        $this->assertCount(1, $groups);
        $this->assertSame($grupoTenantA, $groups[0]['id']);
    }
}
