<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Facades\Authz;
use Gsebastiao\LaravelAuthz\Tests\Fixtures\AuthzUser as User;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * Executa o tutorial "Primeiros passos" do README, passo a passo.
 * Se este teste quebrar, o README precisa ser atualizado.
 */
class ReadmeExampleTest extends TestCase
{
    #[Test]
    public function tutorial_do_readme_funciona_como_escrito(): void
    {
        // 3.1
        config(['authz.permissions' => ['financeiro.ver', 'financeiro.aprovar']]);
        $this->artisan('authz:sync-permissions')->assertSuccessful();

        // 3.2
        $financeiro = Authz::createGroup('Financeiro', 'Equipe do financeiro');
        Authz::grantPermissionToGroup($financeiro, Authz::resolvePermissionId('financeiro.ver'));
        Authz::grantPermissionToGroup($financeiro, Authz::resolvePermissionId('financeiro.aprovar'));

        // 3.3
        $user = User::create(['name' => 'Ana']);
        $user->assignRole('Financeiro');

        // 3.4
        $this->assertTrue($user->hasRole('Financeiro'));
        $this->assertTrue($user->hasPermission('financeiro.aprovar'));
        $this->assertTrue($user->can('financeiro.aprovar'));
        $this->assertFalse($user->hasPermission('rh.ver'));

        // Seção 4: controller
        $this->actingAs($user);
        Gate::authorize('financeiro.aprovar'); // não lança exceção

        // Seção 5: acesso temporário
        Authz::createGroup('Auditoria');
        $user->assignRole('Auditoria', ['end_date' => now()->addDay()->toDateString()]);
        $this->assertTrue($user->hasRole('Auditoria'));
        $this->travel(2)->days();
        $this->assertFalse($user->hasRole('Auditoria'));
    }
}
