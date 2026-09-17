<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Testa a integração de auditoria com gsebastiao/laravel-auditable via
 * stub (tests/Stubs/Gsebastiao/Auditable) — o pacote real é opcional e
 * não é uma dependência deste pacote, então os testes usam um stub que
 * intercepta e registra cada chamada, permitindo confirmar que
 * AuditsCrud chama a API pública dele com os parâmetros certos.
 *
 * getAuditTrail()/applyAuditJoins() usam a CHAVE LÓGICA de tabela
 * ('groups', não 'auth_groups') — resolvida internamente para o nome
 * físico antes de delegar para o laravel-auditable.
 */
class AuditableCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['authz.audit.enabled' => true]);
        Audit::reset();
    }

    /** @test */
    public function create_group_insere_e_audita(): void
    {
        $id = (new Authorization())->createGroup('financeiro', 'grupo do financeiro');

        $this->assertDatabaseHas('auth_groups', ['id' => $id, 'name' => 'financeiro']);

        $trail = (new Authorization())->getAuditTrail('groups', $id);

        $this->assertCount(1, $trail);
        $this->assertSame('created', $trail[0]['event']);
        $this->assertSame('auth_groups', $trail[0]['subjectType']);
    }

    /** @test */
    public function update_group_audita_so_os_campos_que_mudaram(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro', 'descrição original');

        $auth->updateGroup($id, ['description' => 'descrição nova']);

        $trail = $auth->getAuditTrail('groups', $id);
        $updateEntry = collect($trail)->firstWhere('event', 'updated');

        $this->assertNotNull($updateEntry);
        $this->assertArrayHasKey('description', $updateEntry['changes']);
        $this->assertArrayNotHasKey('name', $updateEntry['changes']); // não mudou, não deve aparecer
    }

    /** @test */
    public function delete_group_soft_marca_deleted_at_e_audita(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro');

        $auth->deleteGroup($id);

        $this->assertSoftDeleted('auth_groups', ['id' => $id]);

        $trail = $auth->getAuditTrail('groups', $id);
        $this->assertTrue(collect($trail)->contains('event', 'deleted'));
    }

    /** @test */
    public function delete_group_purge_remove_fisicamente_e_audita(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro');

        $auth->deleteGroup($id, purge: true);

        $this->assertDatabaseMissing('auth_groups', ['id' => $id]);

        $trail = $auth->getAuditTrail('groups', $id);
        $this->assertTrue(collect($trail)->contains('event', 'purged'));
    }

    /** @test */
    public function update_em_id_inexistente_lanca_excecao_e_nao_chama_audit(): void
    {
        $auth = new Authorization();

        try {
            $auth->updateGroup(99999, ['name' => 'não existe']);
            $this->fail('Esperava exceção ao atualizar id inexistente.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        // A checagem de existência acontece ANTES de qualquer transação
        // ou tentativa de escrita — não há nada a auditar como falha
        // aqui, porque não houve tentativa de escrita real.
        $this->assertCount(0, Audit::$calls);
    }

    /** @test */
    public function insert_com_violacao_de_unique_falha_e_audita_como_failed(): void
    {
        $auth = new Authorization();
        $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');

        Audit::reset();

        try {
            $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar duplicado');
            $this->fail('Esperava exceção por violação de unique em permission.');
        } catch (\Throwable $e) {
            // esperado — SQLite lança PDOException/QueryException por unique constraint
        }

        $failCalls = array_filter(Audit::$calls, fn($c) => $c['method'] === 'logFailure');
        $this->assertCount(1, $failCalls);

        $failCall = array_values($failCalls)[0];
        $this->assertSame('auth_permissions', $failCall['subjectType']);
        $this->assertSame('created.failed', $failCall['event']);
    }

    /** @test */
    public function auditoria_de_sucesso_acontece_dentro_da_mesma_transacao_da_escrita(): void
    {
        $auth = new Authorization();

        // withAuditBatch() delega para Audit::transaction() quando a
        // auditoria está ativa -- se auditEvent() estivesse fora do
        // callback de escrita, o rollback abaixo não a desfaria; como
        // está corretamente dentro, o rollback desfaz tudo junto.
        $countBefore = DB::table('auth_groups')->count();

        try {
            DB::transaction(function () use ($auth) {
                $auth->createGroup('dentroDaTransacao');
                throw new \RuntimeException('Força rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        $countAfter = DB::table('auth_groups')->count();
        $this->assertSame($countBefore, $countAfter);
    }

    /** @test */
    public function desligar_auditoria_via_config_para_de_gravar_mas_crud_continua_funcionando(): void
    {
        config(['authz.audit.enabled' => false]);

        $id = (new Authorization())->createGroup('financeiro');

        $this->assertDatabaseHas('auth_groups', ['id' => $id]);
        $this->assertCount(0, Audit::$calls);
    }

    /** @test */
    public function label_columns_do_config_redireciona_qual_coluna_vira_o_rotulo(): void
    {
        // 'description' já existe em auth_groups com valor diferente de
        // 'name' — usa isso pra provar que o config realmente redireciona
        // a coluna lida, sem precisar inventar uma coluna nova no schema.
        config(['authz.audit.label_columns.groups' => 'description']);

        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro', 'Time Financeiro');
        $permId = $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');

        $grantId = $auth->grantPermissionToGroup($groupId, $permId);

        $trail = $auth->getAuditTrail('permissions_groups', $grantId);
        $createEntry = collect($trail)->firstWhere('event', 'created');

        $this->assertSame('Time Financeiro', $createEntry['changes']['group']['label']);
    }
}
