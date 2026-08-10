# Changelog

## [Unreleased]

### Adicionado
- Esqueleto inicial do pacote, portado de uma implementação single-tenant
  existente (model `Authorization`, migrations de `auth_logins`,
  `auth_groups`, `auth_groups_users`, `auth_permissions`,
  `auth_permissions_groups`, `auth_permissions_users`).
- Cascata de prioridade de permissões preservada byte a byte (usuário > deny
  absoluto de grupo > grant de grupo > deny fraco de grupo > padrão negado).
- Emenda de escopo de tenant: `tenant_id` nullable em `auth_groups`,
  contrato `TenantContext` com binding padrão `NullTenantContext` (sempre
  `null` = comportamento single-tenant inalterado).
- Nomes de tabela configuráveis via `config/authz.php`, usados também dentro
  das migrations publicadas.
- Suite de testes cobrindo as 6 branches da cascata, isolamento de tenant e
  nomes de tabela customizados.

### Alterado
- Menus e navegação removidos do escopo do pacote — ficam num pacote
  separado, que consome os ids de permissão retornados aqui. A migration de
  `auth_permissions_menus` (que era inferida, não verificada) foi removida
  junto, não corrigida.
- `getEffectivePermissions()` e `getUserPermissions()` consolidados em um
  único método: `getEffectivePermissions($userId, PermissionFormat $format)`.
  O chamador escolhe `Id`, `Permission` ou `Both` (padrão, comportamento
  anterior inalterado). `getUserPermissions()` foi removido.
- `hasPermission()` passa a aceitar `int|string`: int verifica por
  permission_id, string por nome. String numérica continua sendo tratada
  como nome — não vira busca por id por engano.
- `auth_permissions` ganha `tenant_id` nullable (fora de qualquer unique
  constraint — `permission` continua único sozinho). Permite permissão
  exclusiva de um tenant, necessário porque cada tenant gerencia os
  próprios grupos/permissões via tela self-service, não centralizado.
  Novo método `getAssignablePermissions()` — o que essa tela deve
  consultar para montar o seletor filtrado por tenant. Defesa em
  profundidade em `getEffectivePermissions()`: concessão de grupo cuja
  permissão não é visível ao tenant ativo é ignorada, mesmo que exista no
  banco.

### Adicionado
- Auditoria: nova tabela `auth_audit_table` (`batch`, `subject_type`,
  `subject_id`, `event`, `changes`, `debug_info`, `user_id`). Porte
  adaptado do padrão usado em `BaseModel` (fornecido como referência) —
  transação, batch agrupado, auditoria em sucesso e falha com contexto
  rico — reconstruído como motor genérico (`Concerns\Auditable`) em vez de
  eventos de ciclo de vida do Eloquent, já que este pacote não tem um
  model por tabela. Duas adaptações do padrão de referência: sucesso/falha
  fica codificado no próprio `event` (schema não tem coluna de status), e
  a resolução de labels legíveis (`labelFor()`) é fixa às duas FKs que
  existem aqui (`group_id`, `permission_id`), não um sistema genérico para
  qualquer model.
- CRUD auditado para as 5 tabelas mutáveis: `createGroup`/`updateGroup`/
  `deleteGroup`, `addUserToGroup`/`updateGroupMembership`/
  `removeUserFromGroup`, `createPermission`/`updatePermission`/
  `deletePermission`, `grantPermissionToGroup`/`updateGroupPermission`/
  `revokeGroupPermission`, `grantPermissionToUser`/`updateUserPermission`/
  `revokeUserPermission`. Mais `getAuditTrail()` para consultar o
  histórico de um registro.
- `grantPermissionToGroup()` valida, antes de gravar, que a permissão é
  visível ao tenant do grupo — fecha na escrita o mesmo buraco que a
  defesa em profundidade já cobria na leitura. Falha na validação grava
  `grant.rejected` e lança `RuntimeException`.
- `config/authz.php` → bloco `audit` (`enabled`, `required`).

### Corrigido
- `auth_groups_users.user_id` e `auth_permissions_users.user_id` tinham a
  foreign key presa em `'users'` (string literal), inconsistente com o
  resto do pacote que já era config-driven. Agora lê
  `config('authz.tables.user')`, padrão `'users'`.

### Adicionado (2)
- `applyAuditJoins($tableKey, $query, $events)` — anexa colunas
  `{prefixo}{evento}_at`/`{prefixo}{evento}_by` a uma query via LEFT JOIN,
  sem N+1, para telas de listagem. Adaptação portável (sem SQL
  MySQL-específico) de um padrão de referência fornecido
  (`AuditBuilder::auditJoins()`); eventos, prefixo e coluna de usuário
  exibida vêm de `config('authz.audit.join_events'|'column_prefix'|'user_label_column')`.
- `labelFor()` (usado internamente pelas concessões) passa a ler a coluna
  de rótulo de `config('authz.audit.label_columns.{tabela}')` em vez de
  hardcoded — projeto pode trocar `name`/`permission` por outro nome de
  coluna (`nome`, `label`, etc.) sem editar o pacote.
- `Authorization::table()` lança `InvalidArgumentException` clara para uma
  chave de tabela desconhecida, em vez de deixar `DB::table(null)`
  quebrar de forma confusa mais adiante.

### Adicionado (3)
- Cache opcional (`Concerns\Cacheable`) para `getUserGroups()` e
  `getEffectivePermissions()` — desligado por padrão, ligado via
  `AUTHZ_CACHE_ENABLED=true` no `.env`. Invalidação por registro de chaves
  por usuário (não por tags nem busca por padrão) — funciona
  identicamente em qualquer driver do Cache facade (file, database,
  redis, memcached, array...), diferente de um padrão de referência
  fornecido que só tentava funcionar em Redis e, mesmo lá, tinha bugs
  reais (`Cache::getRedis()->keys()` ignora o prefixo de cache do
  Laravel, e usa `KEYS` em vez de `SCAN`; a lista de chaves por usuário
  nunca era populada, então a invalidação não fazia nada em `file`,
  driver padrão do Laravel).
- Os três formatos de `getEffectivePermissions()` compartilham uma única
  entrada de cache — a conversão de formato acontece depois de ler
  (cache ou fresco), não antes.
- Invalidação automática nas funções de CRUD: escrita em usuário
  individual invalida só aquele usuário; escrita em grupo (concessão,
  status, exclusão) invalida todo membro atual do grupo.
  `updatePermission()`/`deletePermission()` deliberadamente não invalidam
  automaticamente (blast radius exigiria cruzar 3 tabelas para uma ação
  rara) — documentado, com `forgetUserCache()` manual como alternativa.
  `config('authz.cache.invalidate_on_write')` desliga a automação sem
  desligar a chamada manual.
- README reescrito com início rápido, seção conceitual ("Como funciona"),
  FAQ com as dúvidas reais que surgiram durante o desenvolvimento, e
  índice — pensado para alguém que nunca viu o pacote entender o básico
  em poucos minutos.

### Pendências conhecidas
- Decisão de negócio em aberto: negação individual (`auth_permissions_users`)
  vale cross-tenant ou por tenant? Hoje: global (comportamento original
  preservado).

## Estratégia de versionamento

`0.x` até validação em um segundo projeto real; a partir daí, semver 1.0.
