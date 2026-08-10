<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class AuditableCrudTest extends TestCase
{
    /** @test */
    public function create_group_insere_e_audita(): void
    {
        $id = (new Authorization())->createGroup('financeiro', 'grupo do financeiro');

        $this->assertDatabaseHas('auth_groups', ['id' => $id, 'name' => 'financeiro']);

        $trail = (new Authorization())->getAuditTrail('auth_groups', $id);

        $this->assertCount(1, $trail);
        $this->assertSame('created', $trail[0]['event']);
        $this->assertNotEmpty($trail[0]['batch']);
        $this->assertNull($trail[0]['debug_info']); // só preenchido em falha
    }

    /** @test */
    public function update_group_audita_so_os_campos_que_mudaram(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro', 'descrição original');

        $auth->updateGroup($id, ['description' => 'descrição nova']);

        $trail = $auth->getAuditTrail('auth_groups', $id);
        $updateEntry = collect($trail)->firstWhere('event', 'updated');

        $this->assertNotNull($updateEntry);

        $changes = json_decode($updateEntry['changes'], true);
        $this->assertArrayHasKey('description', $changes);
        $this->assertArrayNotHasKey('name', $changes); // não mudou, não deve aparecer
    }

    /** @test */
    public function delete_group_soft_marca_deleted_at_e_audita(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro');

        $auth->deleteGroup($id);

        $this->assertSoftDeleted('auth_groups', ['id' => $id]);

        $trail = $auth->getAuditTrail('auth_groups', $id);
        $this->assertTrue(collect($trail)->contains('event', 'deleted'));
    }

    /** @test */
    public function delete_group_purge_remove_fisicamente_e_audita(): void
    {
        $auth = new Authorization();
        $id = $auth->createGroup('financeiro');

        $auth->deleteGroup($id, purge: true);

        $this->assertDatabaseMissing('auth_groups', ['id' => $id]);

        $trail = $auth->getAuditTrail('auth_groups', $id);
        $this->assertTrue(collect($trail)->contains('event', 'purged'));
    }

    /** @test */
    public function update_em_id_inexistente_falha_e_audita_como_failed(): void
    {
        $auth = new Authorization();

        try {
            $auth->updateGroup(99999, ['name' => 'não existe']);
            $this->fail('Esperava exceção ao atualizar id inexistente.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $trail = $auth->getAuditTrail('auth_groups', 99999);
        $this->assertCount(1, $trail);
        $this->assertSame('updated.failed', $trail[0]['event']);
        $this->assertNotNull($trail[0]['debug_info']); // preenchido em falha
    }

    /** @test */
    public function insert_com_violacao_de_unique_falha_e_audita_como_failed(): void
    {
        $auth = new Authorization();
        $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');

        try {
            $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar duplicado');
            $this->fail('Esperava exceção por violação de unique em permission.');
        } catch (\Throwable $e) {
            // esperado — SQLite lança PDOException/QueryException por unique constraint
        }

        $failedCount = DB::table('auth_audit_table')
            ->where('subject_type', 'auth_permissions')
            ->where('event', 'created.failed')
            ->count();

        $this->assertSame(1, $failedCount);
    }

    /** @test */
    public function operacoes_na_mesma_transacao_compartilham_o_mesmo_batch(): void
    {
        $auth = new Authorization();

        DB::transaction(function () use ($auth) {
            $groupId = $auth->createGroup('financeiro');
            $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
        });

        $batches = DB::table('auth_audit_table')->pluck('batch')->unique();

        $this->assertCount(1, $batches);
    }

    /** @test */
    public function operacoes_fora_de_transacao_tem_batches_diferentes(): void
    {
        $auth = new Authorization();

        $auth->createGroup('financeiro');
        $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');

        $batches = DB::table('auth_audit_table')->pluck('batch')->unique();

        $this->assertCount(2, $batches);
    }

    /** @test */
    public function desligar_auditoria_via_config_para_de_gravar_mas_crud_continua_funcionando(): void
    {
        config(['authz.audit.enabled' => false]);

        $id = (new Authorization())->createGroup('financeiro');

        $this->assertDatabaseHas('auth_groups', ['id' => $id]);
        $this->assertSame(0, DB::table('auth_audit_table')->count());
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

        $trail = $auth->getAuditTrail('auth_permissions_groups', $grantId);
        $changes = json_decode($trail[0]['changes'], true);

        $this->assertSame('Time Financeiro', $changes['group']['label']);
    }
}
