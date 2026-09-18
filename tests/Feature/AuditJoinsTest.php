<?php

namespace Gsebastiao\LaravelAuthz\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Gsebastiao\LaravelAuthz\Models\Authorization;
use Gsebastiao\LaravelAuthz\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelAuthz\Tests\TestCase;

class AuditJoinsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['authz.audit.enabled' => true]);
        \Gsebastiao\Auditable\Audit::reset();
    }

    private function actingUser(string $name): TestUser
    {
        $user = TestUser::create(['name' => $name]);
        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function colunas_padrao_mostram_criado_por_e_em_com_atualizado_nulo(): void
    {
        $this->actingUser('Ana');
        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro');

        $row = $auth->applyAuditJoins('groups')
            ->where('auth_groups.id', $groupId)
            ->first();

        $this->assertNotNull($row->audit_created_at);
        $this->assertSame('Ana', $row->audit_created_by);
        // Ninguém atualizou ainda — LEFT JOIN sem correspondência = null,
        // não erro nem linha ausente.
        $this->assertNull($row->audit_updated_at);
        $this->assertNull($row->audit_updated_by);
    }

    #[Test]
    public function apos_update_colunas_de_updated_ficam_preenchidas(): void
    {
        $this->actingUser('Ana');
        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro');

        $this->actingUser('Bruno');
        $auth->updateGroup($groupId, ['description' => 'nova descrição']);

        $row = $auth->applyAuditJoins('groups')
            ->where('auth_groups.id', $groupId)
            ->first();

        $this->assertSame('Ana', $row->audit_created_by);
        $this->assertSame('Bruno', $row->audit_updated_by);
    }

    #[Test]
    public function pega_o_evento_mais_recente_quando_ha_mais_de_um_update(): void
    {
        $this->actingUser('Ana');
        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro');

        $this->actingUser('Bruno');
        $auth->updateGroup($groupId, ['description' => 'primeira edição']);

        $this->actingUser('Carla');
        $auth->updateGroup($groupId, ['description' => 'segunda edição']);

        $row = $auth->applyAuditJoins('groups')
            ->where('auth_groups.id', $groupId)
            ->first();

        // Deve trazer o ÚLTIMO update (Carla), não o primeiro (Bruno).
        $this->assertSame('Carla', $row->audit_updated_by);
    }

    #[Test]
    public function events_customizado_traz_so_as_colunas_pedidas(): void
    {
        $this->actingUser('Ana');
        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro');

        $row = (array) $auth->applyAuditJoins('groups', events: ['created'])
            ->where('auth_groups.id', $groupId)
            ->first();

        $this->assertArrayHasKey('audit_created_at', $row);
        $this->assertArrayNotHasKey('audit_updated_at', $row);
    }

    #[Test]
    public function prefixo_de_coluna_customizado_via_config(): void
    {
        config(['authz.audit.column_prefix' => 'log_']);

        $this->actingUser('Ana');
        $auth = new Authorization();
        $groupId = $auth->createGroup('financeiro');

        $row = (array) $auth->applyAuditJoins('groups', events: ['created'])
            ->where('auth_groups.id', $groupId)
            ->first();

        $this->assertArrayHasKey('log_created_at', $row);
        $this->assertArrayHasKey('log_created_by', $row);
    }

    #[Test]
    public function chave_de_tabela_desconhecida_lanca_excecao_clara(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Authorization())->applyAuditJoins('tabela_que_nao_existe');
    }
}
