<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;
use Gsebastiao\LaravelAuthz\Exceptions\AuthzException;
use Gsebastiao\LaravelAuthz\Facades\Authz;
use Gsebastiao\LaravelAuthz\Tests\Fixtures\AuthzUser;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Gsebastiao\LaravelAuthz\Traits\HasPermissions;
use Gsebastiao\LaravelAuthz\Traits\HasRoles;
use Illuminate\Foundation\Auth\User;
use PHPUnit\Framework\Attributes\Test;

class UserTraitsTest extends TestCase
{
    #[Test]
    public function has_roles_e_has_permissions_podem_ser_usados_juntos(): void
    {
        // Antes: erro fatal (os dois traits definiam authz()).
        $user = new class extends User {
            use HasRoles, HasPermissions;
        };

        $this->assertTrue(method_exists($user, 'hasRole') && method_exists($user, 'hasPermission'));
    }

    #[Test]
    public function fluxo_basico_pelo_model(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        Authz::createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
        $group = Authz::createGroup('financeiro');
        Authz::grantPermissionToGroup($group, Authz::resolvePermissionId('financeiro.aprovar'));

        $user->assignRole('financeiro');

        $this->assertTrue($user->hasRole('financeiro'));
        $this->assertTrue($user->hasPermission('financeiro.aprovar'));
        $this->assertTrue($user->can('financeiro.aprovar'));
        $this->assertSame(['financeiro.aprovar'], $user->getPermissionNames());
    }

    #[Test]
    public function remove_role_funciona(): void
    {
        // Antes: erro fatal "Cannot use object of type stdClass as array".
        $user = AuthzUser::create(['name' => 'Ana']);
        Authz::createGroup('financeiro');
        $user->assignRole('financeiro');

        $this->assertTrue($user->removeRole('financeiro'));
        $this->assertFalse($user->hasRole('financeiro'));
        $this->assertFalse($user->removeRole('financeiro'));
    }

    #[Test]
    public function assign_role_inexistente_mostra_o_nome_no_erro(): void
    {
        $this->expectException(AuthzException::class);
        $this->expectExceptionMessage("Grupo 'nao-existe' não encontrado.");

        AuthzUser::create(['name' => 'Ana'])->assignRole('nao-existe');
    }

    #[Test]
    public function sync_roles_com_nome_errado_nao_altera_nada(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        Authz::createGroup('financeiro');
        $user->assignRole('financeiro');

        try {
            $user->syncRoles(['rh-com-erro-de-digitacao']);
        } catch (AuthzException) {
        }

        $this->assertTrue($user->hasRole('financeiro'));
    }

    #[Test]
    public function sync_roles_deixa_exatamente_os_grupos_da_lista(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        foreach (['a', 'b', 'c'] as $name) {
            Authz::createGroup($name);
        }
        $user->assignRoles(['a', 'b']);

        $user->syncRoles(['b', 'c']);

        $this->assertEqualsCanonicalizing(['b', 'c'], $user->getRoleNames());
    }

    #[Test]
    public function revoke_permission_nao_apaga_uma_negacao(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        Authz::createPermission('a.b', 'a', 'b', 'AB');
        $user->denyPermission('a.b');

        $this->assertFalse($user->revokePermission('a.b')); // não havia concessão
        $this->assertFalse($user->hasPermission('a.b'));

        $this->assertTrue($user->clearPermissionOverride('a.b'));
    }

    #[Test]
    public function sync_permissions_mexe_so_nas_concessoes_diretas(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        foreach (['a.1', 'a.2', 'a.3'] as $p) {
            Authz::createPermission($p, 'a', $p, $p);
        }
        $group = Authz::createGroup('g');
        Authz::grantPermissionToGroup($group, Authz::resolvePermissionId('a.3'));
        $user->assignRole('g');
        $user->grantPermission('a.1');

        $user->syncPermissions(['a.2']);

        $this->assertSame([Authz::resolvePermissionId('a.2')], $user->directPermissionIds());
        $this->assertEqualsCanonicalizing(['a.2', 'a.3'], $user->getPermissionNames()); // a.3 vem do grupo
    }

    #[Test]
    public function set_primary_role(): void
    {
        $user = AuthzUser::create(['name' => 'Ana']);
        Authz::createGroup('a');
        Authz::createGroup('b');
        $user->assignRoles(['a', 'b']);

        $this->assertTrue($user->setPrimaryRole('a'));
        $this->assertSame('a', $user->getPrimaryRole()['name']);

        $this->assertTrue($user->setPrimaryRole('b'));
        $this->assertSame('b', $user->getPrimaryRole()['name']);

        Authz::createGroup('c');
        $this->assertFalse($user->setPrimaryRole('c')); // não é membro: nada muda
        $this->assertSame('b', $user->getPrimaryRole()['name']);
    }

    #[Test]
    public function nome_de_grupo_e_procurado_so_no_tenant_ativo(): void
    {
        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null { return 1; }
        });
        $groupTenant1 = Authz::createGroup('financeiro');

        $this->app->bind(TenantContext::class, fn() => new class implements TenantContext {
            public function id(): int|string|null { return 2; }
        });
        $groupTenant2 = Authz::createGroup('financeiro');

        $user = AuthzUser::create(['name' => 'Ana']);
        $user->assignRole('financeiro');

        $this->assertDatabaseHas('auth_groups_users', ['user_id' => $user->id, 'group_id' => $groupTenant2]);
        $this->assertDatabaseMissing('auth_groups_users', ['user_id' => $user->id, 'group_id' => $groupTenant1]);
    }

    #[Test]
    public function get_users_with_role(): void
    {
        Authz::createGroup('financeiro');
        $ana = AuthzUser::create(['name' => 'Ana']);
        AuthzUser::create(['name' => 'Bruno']);
        $ana->assignRole('financeiro');

        $this->assertSame(['Ana'], AuthzUser::getUsersWithRole('financeiro')->pluck('name')->all());
        $this->assertCount(0, AuthzUser::getUsersWithRole('nao-existe'));
    }
}
