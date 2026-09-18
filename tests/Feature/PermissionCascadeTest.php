<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PermissionCascadeTest extends TestCase
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

    private function group(string $name): int
    {
        return DB::table('auth_groups')->insertGetId([
            'name' => $name,
            'status' => 1,
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

    private function grantToUser(int $userId, int $permissionId, bool $granted): void
    {
        DB::table('auth_permissions_users')->insert([
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'is_granted' => $granted,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantToGroup(int $groupId, int $permissionId, bool $granted, bool $absolute = false): void
    {
        DB::table('auth_permissions_groups')->insert([
            'group_id' => $groupId,
            'permission_id' => $permissionId,
            'is_granted' => $granted,
            'is_absolute' => $absolute,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function passo_1_negacao_individual_vence_concessao_de_grupo(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $groupId = $this->group('financeiro');

        $this->addToGroup($userId, $groupId);
        $this->grantToGroup($groupId, $permId, granted: true); // grupo concede
        $this->grantToUser($userId, $permId, granted: false);  // usuário nega

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    #[Test]
    public function passo_2_concessao_individual_vence_ausencia_de_grupo(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');

        $this->grantToUser($userId, $permId, granted: true);

        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    #[Test]
    public function passo_3_deny_absoluto_de_grupo_vence_concessao_de_outro_grupo(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $grupoQueConcede = $this->group('financeiro');
        $grupoQueNegaAbsoluto = $this->group('compliance');

        $this->addToGroup($userId, $grupoQueConcede);
        $this->addToGroup($userId, $grupoQueNegaAbsoluto);

        $this->grantToGroup($grupoQueConcede, $permId, granted: true);
        $this->grantToGroup($grupoQueNegaAbsoluto, $permId, granted: false, absolute: true);

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    #[Test]
    public function passo_4_concessao_de_grupo_vence_deny_fraco_de_outro_grupo(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $grupoQueConcede = $this->group('financeiro');
        $grupoQueNegaFraco = $this->group('padrao');

        $this->addToGroup($userId, $grupoQueConcede);
        $this->addToGroup($userId, $grupoQueNegaFraco);

        $this->grantToGroup($grupoQueConcede, $permId, granted: true);
        $this->grantToGroup($grupoQueNegaFraco, $permId, granted: false, absolute: false);

        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    #[Test]
    public function passo_5_so_deny_fraco_resulta_em_negado(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $grupoQueNegaFraco = $this->group('padrao');

        $this->addToGroup($userId, $grupoQueNegaFraco);
        $this->grantToGroup($grupoQueNegaFraco, $permId, granted: false, absolute: false);

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }

    #[Test]
    public function passo_6_nenhum_registro_resulta_em_negado_por_padrao(): void
    {
        $userId = $this->user();
        $this->permission('financeiro.aprovar'); // existe no catálogo, mas ninguém concedeu nada

        $this->assertFalse((new Authorization())->hasPermission('financeiro.aprovar', $userId));
    }
}
