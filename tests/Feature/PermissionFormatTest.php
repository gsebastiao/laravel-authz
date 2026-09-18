<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PermissionFormatTest extends TestCase
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

    private function grantToUser(int $userId, int $permissionId): void
    {
        DB::table('auth_permissions_users')->insert([
            'user_id' => $userId,
            'permission_id' => $permissionId,
            'is_granted' => true,
            'start_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function sem_format_explicito_retorna_id_e_permission_juntos(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        $result = (new Authorization())->getEffectivePermissions($userId);

        $this->assertSame([['id' => $permId, 'permission' => 'financeiro.aprovar']], $result);
    }

    #[Test]
    public function format_id_retorna_so_os_ids(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        $result = (new Authorization())->getEffectivePermissions($userId, PermissionFormat::Id);

        $this->assertSame([$permId], $result);
    }

    #[Test]
    public function format_permission_retorna_so_as_strings(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        $result = (new Authorization())->getEffectivePermissions($userId, PermissionFormat::Permission);

        $this->assertSame(['financeiro.aprovar'], $result);
    }

    #[Test]
    public function has_permission_continua_funcionando_por_nome(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        $this->assertTrue((new Authorization())->hasPermission('financeiro.aprovar', $userId));
        $this->assertFalse((new Authorization())->hasPermission('financeiro.rejeitar', $userId));
    }

    #[Test]
    public function has_permission_aceita_id_como_int(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        $this->assertTrue((new Authorization())->hasPermission($permId, $userId));
        $this->assertFalse((new Authorization())->hasPermission($permId + 999, $userId));
    }

    #[Test]
    public function has_permission_trata_string_numerica_como_nome_nao_como_id(): void
    {
        $userId = $this->user();
        $permId = $this->permission('financeiro.aprovar');
        $this->grantToUser($userId, $permId);

        // (string) $permId nunca vai bater com o nome 'financeiro.aprovar' -
        // prova que string numérica não vira busca por id por engano.
        $this->assertFalse((new Authorization())->hasPermission((string) $permId, $userId));
    }
}
