<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Facades\Authz;
use Gsebastiao\LaravelAuthz\Tests\Fixtures\AuthzUser;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regras de decisão corrigidas nesta versão, e a garantia de que o filtro
 * SQL whereHasPermission() dá SEMPRE a mesma resposta que hasPermission().
 */
class PermissionRulesTest extends TestCase
{
    private function user(): int
    {
        return DB::table('users')->insertGetId(['name' => 'u']);
    }

    private function assertBothAgree(bool $expected, string $permission, int $userId): void
    {
        $this->assertSame($expected, Authz::hasPermission($permission, $userId), 'hasPermission()');
        $this->assertSame(
            $expected,
            AuthzUser::whereHasPermission($permission)->whereKey($userId)->exists(),
            'whereHasPermission()'
        );
    }

    #[Test]
    public function grupo_desativado_nao_concede_permissao(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
        $group = Authz::createGroup('financeiro');
        Authz::addUserToGroup($userId, $group);
        Authz::grantPermissionToGroup($group, $perm);

        $this->assertBothAgree(true, 'financeiro.aprovar', $userId);

        Authz::updateGroup($group, ['status' => 0]);

        $this->assertBothAgree(false, 'financeiro.aprovar', $userId);
        $this->assertSame([], Authz::getUserGroups($userId));
    }

    #[Test]
    public function start_date_no_futuro_ainda_nao_vale(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');

        Authz::grantPermissionToUser($userId, $perm, ['start_date' => now()->addDays(3)]);
        $this->assertBothAgree(false, 'financeiro.aprovar', $userId);

        $this->travel(3)->days();
        $this->assertBothAgree(true, 'financeiro.aprovar', $userId);
    }

    #[Test]
    public function end_date_no_passado_ja_nao_vale(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
        $group = Authz::createGroup('financeiro');
        Authz::addUserToGroup($userId, $group, ['end_date' => now()->addDay()]);
        Authz::grantPermissionToGroup($group, $perm);

        $this->assertBothAgree(true, 'financeiro.aprovar', $userId);
        $this->travel(2)->days();
        $this->assertBothAgree(false, 'financeiro.aprovar', $userId);
    }

    #[Test]
    public function negacao_individual_vence_concessao_individual_em_qualquer_ordem(): void
    {
        $perm = DB::table('auth_permissions')->insertGetId([
            'permission' => 'x.y', 'module' => 'x', 'action' => 'y', 'label' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[true, false], [false, true]] as $order) {
            $userId = $this->user();
            foreach ($order as $granted) { // linhas duplicadas inseridas direto no banco
                DB::table('auth_permissions_users')->insert([
                    'user_id' => $userId, 'permission_id' => $perm, 'is_granted' => $granted,
                    'start_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->assertBothAgree(false, 'x.y', $userId);
        }
    }

    #[Test]
    public function permissao_desativada_ou_apagada_nao_vale_em_nenhum_dos_caminhos(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
        Authz::grantPermissionToUser($userId, $perm);

        Authz::updatePermission($perm, ['status' => 0]);
        $this->assertBothAgree(false, 'financeiro.aprovar', $userId);

        Authz::updatePermission($perm, ['status' => 1]);
        Authz::deletePermission($perm);
        $this->assertBothAgree(false, 'financeiro.aprovar', $userId);
    }

    #[Test]
    public function concessao_individual_de_permissao_de_outro_tenant_e_ignorada(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('exportar.massa', 'relatorios', 'exportar', 'Exportar', tenantId: 1);
        Authz::grantPermissionToUser($userId, $perm);

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null { return 2; }
        });
        $this->assertBothAgree(false, 'exportar.massa', $userId);

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null { return 1; }
        });
        $this->assertBothAgree(true, 'exportar.massa', $userId);
    }

    #[Test]
    public function nome_da_permissao_nao_diferencia_maiusculas_nos_dois_caminhos(): void
    {
        $userId = $this->user();
        $perm = Authz::createPermission('Financeiro.Aprovar', 'financeiro', 'aprovar', 'Aprovar');
        Authz::grantPermissionToUser($userId, $perm);

        $this->assertBothAgree(true, 'financeiro.aprovar', $userId);
    }

    #[Test]
    public function cascata_completa_bate_entre_php_e_sql(): void
    {
        $perm = Authz::createPermission('p.x', 'p', 'x', 'P');
        $grants = Authz::createGroup('concede');
        $weakDeny = Authz::createGroup('nega-fraco');
        $strongDeny = Authz::createGroup('nega-forte');
        Authz::grantPermissionToGroup($grants, $perm);
        Authz::grantPermissionToGroup($weakDeny, $perm, ['is_granted' => false]);
        Authz::grantPermissionToGroup($strongDeny, $perm, ['is_granted' => false, 'is_absolute' => true]);

        $cases = [
            'só concede' => [[$grants], null, true],
            'concede + nega fraco' => [[$grants, $weakDeny], null, true],
            'concede + nega forte' => [[$grants, $strongDeny], null, false],
            'só nega fraco' => [[$weakDeny], null, false],
            'nega forte + individual concede' => [[$strongDeny], true, true],
            'concede + individual nega' => [[$grants], false, false],
            'nada' => [[], null, false],
        ];

        foreach ($cases as $label => [$groups, $individual, $expected]) {
            $userId = $this->user();
            foreach ($groups as $g) {
                Authz::addUserToGroup($userId, $g);
            }
            if ($individual !== null) {
                Authz::grantPermissionToUser($userId, $perm, ['is_granted' => $individual]);
            }

            $this->assertSame($expected, Authz::hasPermission('p.x', $userId), "hasPermission: {$label}");
            $this->assertSame($expected, AuthzUser::whereHasPermission('p.x')->whereKey($userId)->exists(), "SQL: {$label}");
        }
    }
}
