<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Facades\Authz;
use Gsebastiao\LaravelAuthz\Tests\Fixtures\AuthzUser;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/** Gate, middlewares, Blade e comandos artisan. */
class LaravelIntegrationTest extends TestCase
{
    private AuthzUser $ana;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['financeiro.ver', 'financeiro.aprovar', 'rh.ver'] as $p) {
            Authz::createPermission($p, 'm', 'a', $p);
        }
        $group = Authz::createGroup('financeiro');
        Authz::grantPermissionToGroup($group, Authz::resolvePermissionId('financeiro.ver'));

        $this->ana = AuthzUser::create(['name' => 'Ana']);
        $this->ana->assignRole('financeiro');
    }

    #[Test]
    public function gate_entende_as_permissoes(): void
    {
        $this->actingAs($this->ana);

        $this->assertTrue(Gate::allows('financeiro.ver'));
        $this->assertFalse(Gate::allows('financeiro.aprovar'));
        $this->assertFalse(Gate::allows('permissao.que.nao.existe'));
    }

    #[Test]
    public function gate_do_projeto_tem_prioridade_e_visitante_e_negado(): void
    {
        Gate::define('financeiro.ver', fn() => false);

        $this->assertFalse(Gate::forUser($this->ana)->allows('financeiro.ver'));
        $this->assertFalse(Gate::allows('financeiro.ver')); // sem ninguém logado
    }

    #[Test]
    public function super_admin_via_gate_before_continua_funcionando(): void
    {
        Gate::before(fn($user) => $user->name === 'Ana' ? true : null);

        $this->assertTrue(Gate::forUser($this->ana)->allows('financeiro.aprovar'));
    }

    #[Test]
    public function middleware_de_permissao(): void
    {
        Route::get('/uma', fn() => 'ok')->middleware('authz.permission:financeiro.ver');
        Route::get('/qualquer', fn() => 'ok')->middleware('authz.permission:rh.ver|financeiro.ver');
        Route::get('/todas', fn() => 'ok')->middleware('authz.permission:financeiro.ver,financeiro.aprovar');
        Route::get('/grupo', fn() => 'ok')->middleware('authz.role:financeiro');

        $this->getJson('/uma')->assertUnauthorized(); // visitante

        $this->actingAs($this->ana);
        $this->get('/uma')->assertOk();
        $this->get('/qualquer')->assertOk();
        $this->get('/todas')->assertForbidden(); // antes: só a 1ª era checada
        $this->get('/grupo')->assertOk();
    }

    #[Test]
    public function diretivas_blade(): void
    {
        $this->actingAs($this->ana);

        $html = Blade::render(<<<'BLADE'
            @hasPermission('financeiro.ver') A @endHasPermission
            @hasPermission('financeiro.aprovar') B @endHasPermission
            @hasAnyPermission(['rh.ver', 'financeiro.ver']) C @endHasAnyPermission
            @hasAllPermissions(['rh.ver', 'financeiro.ver']) D @endHasAllPermissions
            @hasRole('financeiro') E @endHasRole
            @can('financeiro.ver') F @endcan
            BLADE);

        $this->assertSame('ACEF', preg_replace('/\s+/', '', $html));
    }

    #[Test]
    public function sync_permissions_aceita_forma_curta_e_recupera_apagadas(): void
    {
        Authz::deletePermission(Authz::resolvePermissionId('rh.ver'));

        config(['authz.permissions' => [
            'rh.ver',
            ['permission' => 'estoque.baixar', 'label' => 'Dar baixa no estoque'],
        ]]);

        // Antes: erro de UNIQUE ao tentar recriar 'rh.ver'.
        $this->artisan('authz:sync-permissions')->assertSuccessful();

        $this->assertDatabaseHas('auth_permissions', ['permission' => 'rh.ver', 'deleted_at' => null]);
        $this->assertDatabaseHas('auth_permissions', [
            'permission' => 'estoque.baixar', 'module' => 'estoque', 'action' => 'baixar', 'label' => 'Dar baixa no estoque',
        ]);
    }
}
