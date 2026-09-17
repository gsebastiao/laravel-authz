# Changelog

## [Unreleased]

### Adicionado
- Esqueleto inicial do pacote, portado de uma implementação single-tenant
  existente (model `Authorization`, migrations de `auth_groups`,
  `auth_groups_users`, `auth_permissions`, `auth_permissions_groups`,
  `auth_permissions_users`).
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

### Corrigido — revisão completa antes da primeira publicação

O esqueleto inicial (acima) chegou a esta revisão com vários problemas que
o impediam de funcionar de verdade: erro fatal de sintaxe nos 5 models
mutáveis (`protected $table = config(...)` — `config()` é chamada de
função, não é uma expressão constante válida como valor de propriedade de
classe), métodos de CRUD (`createGroup` etc.) definidos nos models em vez
de `Authorization` (onde o README e os testes já assumiam que estariam),
15 classes de evento referenciadas mas nunca criadas, três tentativas
paralelas e nenhuma funcional de "aplicar auditoria condicionalmente"
(traits vazios com comentário admitindo que não é possível adicionar
trait dinamicamente depois da classe já declarada), `Gate`/`Blade`/`DB`
usados sem import em vários arquivos, `Http/Middleware.php` com namespace
incompatível com o path PSR-4, e outros métodos com lógica vazia ou morta
(`SyncPermissionsCommand::handle()`, a macro `whereHasPermission`).

Esta seção documenta a correção completa, arquivo por arquivo:

- **Os 5 models mutáveis** (`Group`, `Permission`, `GroupUser`,
  `PermissionGroup`, `PermissionUser`) foram reconstruídos como Eloquent
  "burro" — só `$fillable`/`casts()`, sem nenhuma lógica de CRUD. `$table`
  passa a ser resolvido no construtor via `$this->setTable(config(...))`
  antes de `parent::__construct()`, não mais como valor de propriedade.
  Toda lógica de escrita (os 15 métodos de CRUD) mudou para
  `Models\Authorization.php`, com a assinatura exata que
  `Facades\Authz.php` já documentava via `@method`.
- **Auditoria condicional, resolvida corretamente**: em vez de tentar
  aplicar uma trait a uma classe já declarada (impossível em PHP), a
  resolução acontece via `class_alias()` em
  `Concerns\AuditableModelSupport.php`, carregado antes de qualquer model
  via `composer.json > autoload > files` (mesmo mecanismo de
  `helpers.php`). `Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait`
  vira um alias para o trait real do `gsebastiao/laravel-auditable`
  quando ele está instalado, ou para `Concerns\NoOpAuditable` (vazio)
  caso contrário. Os 5 models usam `use ResolvedAuditableTrait;`
  incondicionalmente. Auditoria dos 15 métodos de CRUD de
  `Authorization` (que não são models Eloquent) fica em
  `Concerns\AuditsCrud`, delegando para a API pública do
  laravel-auditable (`Audit::log()`, `Audit::logFailure()`,
  `Audit::transaction()`, `Audit::trailFor()`,
  `AuditColumnJoiner::apply()`) só quando `authz.audit.enabled` está
  ligado e o pacote está instalado — no-op transparente caso contrário,
  em vez de uma tabela de auditoria caseira própria.
- **16 classes de evento criadas** em `src/Events/` — uma por operação de
  CRUD (`GroupCreated`, `GroupUpdated`, `GroupDeleted`,
  `UserAddedToGroup`, `GroupMembershipUpdated`, `UserRemovedFromGroup`,
  `PermissionCreated`, `PermissionUpdated`, `PermissionDeleted`,
  `PermissionGrantedToGroup`, `PermissionUpdatedInGroup`,
  `PermissionRevokedFromGroup`, `PermissionGrantedToUser`,
  `PermissionUpdatedForUser`, `PermissionRevokedFromUser`), mais
  `PermissionGrantToGroupRejected`, disparada quando
  `grantPermissionToGroup()` rejeita uma concessão de tenant cruzado.
- **Toda escrita agora roda dentro de transação** (`AuditsCrud::withAuditBatch()`
  — `Audit::transaction()` quando a auditoria está ativa, `DB::transaction()`
  simples caso contrário), com o registro de auditoria de sucesso
  **dentro** do callback (mesmo batch/transação da escrita de negócio) e
  o de falha **fora**, no `catch` de quem chama (precisa sobreviver ao
  rollback que o levou a disparar).
- Todo `updateX`/`deleteX`/`revokeX`/`removeX` passou a lançar
  `\RuntimeException` explícita quando o id não existe, em vez de
  retornar `false` silenciosamente.
- **`Gates`**: `LaravelAuthzServiceProvider` registra um `Gate::define()`
  por permissão do catálogo (via `Authorization::registerGates()`),
  condicional a `config('authz.gates.auto_register')`.
- **Middleware**: movido de `src/Http/Middleware.php` (namespace
  incompatível com PSR-4) para `src/Http/Middleware/PermissionMiddleware.php`,
  registrado como alias `authz.permission` no ServiceProvider.
- **`whereHasPermission`**: implementada do zero em
  `Authorization::applyWhereHasPermission()` e registrada como macro do
  Eloquent Builder — replica a cascata de precedência de
  `hasPermission()` via `whereExists`/`whereNotExists` aninhados, sem
  N+1, para uso em listagens (`User::whereHasPermission('x')->get()`).
- **Blade directives**: `@hasPermission`, `@hasAnyPermission`, `@hasRole`
  e os `@end*` correspondentes, registradas no ServiceProvider.
- **`SyncPermissionsCommand`** (`php artisan authz:sync-permissions`)
  reescrito com lógica real: lê `config('authz.permissions')`, cria o que
  falta, atualiza só os campos que mudaram no que já existe, nunca
  deleta automaticamente (lista órfãos para revisão manual).
- `auth_groups_users.user_id` e `auth_permissions_users.user_id` tinham a
  foreign key presa em `'users'` (string literal), inconsistente com o
  resto do pacote que já era config-driven. Agora lê
  `config('authz.tables.users')`, padrão `'users'`.
- `Authorization::table()` lança `InvalidArgumentException` clara para uma
  chave de tabela desconhecida, em vez de deixar `DB::table(null)`
  quebrar de forma confusa mais adiante.
- `config/authz.php` ganhou `audit.column_prefix`, `audit.join_events`,
  `audit.label_columns`, `user_model`, `gates.auto_register`,
  `permissions` (catálogo declarativo).

### Removido
- `src/Support/HierarchicalTenantContext.php` — usava campos inventados
  de domínio de negócio (`current_delegacao`, `sede_id`) e contradizia o
  próprio README, que documenta que hierarquia de tenant não é suportada.
- `can()`/`cannot()` de `src/Helpers/helpers.php` — nomes muito comuns,
  risco real de colisão com o que o projeto host já define (inclusive o
  `can()`/`cannot()` nativo do próprio Laravel). `hasPermission()` cobre
  o mesmo caso de uso sem esse risco.

### Corrigido — bugs encontrados só por execução real, não por leitura

Estes só apareceram rodando o código de verdade contra SQLite; leitura
estática e `php -l` não os teriam detectado:

- **Defesa de tenant desligada quando não há tenant ativo** (o mais
  sério — pré-existente no esqueleto inicial). Em
  `computeEffectivePermissions()`, a defesa contra concessão de
  permissão de outro tenant usava
  `->when(self::activeTenantId(), function ($q, $tenantId) { ... })` —
  `when()` do Laravel só executa o callback com condição truthy, e com
  `NullTenantContext` (padrão do pacote, tenant ativo = `null`), a
  defesa em profundidade contra dado corrompido/indevido ficava
  **completamente desligada** no modo mais comum de uso. Confirmado
  inserindo uma concessão indevida direto no banco (permissão exclusiva
  de um tenant, concedida a grupo de outro) e `hasPermission()`
  retornava `true` quando devia ser `false`. Corrigido para a condição
  `whereNull(tenant_id)` rodar sempre, com a parte de tenant específico
  só se somando quando há tenant ativo.
- **`applyWhereHasPermission()`: coluna ambígua em subqueries.** Quando
  `$userIdColumn` era passado sem qualificação de tabela (`'id'`, o
  padrão), as subqueries internas (que também têm colunas `id`/`user_id`
  de outras tabelas) geravam `SQLSTATE[HY000]: ambiguous column name`.
  Corrigido qualificando `$userIdColumn` automaticamente com
  `$query->getModel()->getTable()` quando vem sem qualificação.
- **`unique(['tenant_id', 'name'])` não impedia grupos duplicados no modo
  padrão.** `NULL` não é igual a `NULL` em constraints `UNIQUE` na
  maioria dos bancos (SQLite, PostgreSQL, SQL Server — MySQL é a
  exceção, que trata `NULL`s como iguais aqui). Com `tenant_id` sempre
  `null` no modo single-tenant padrão do pacote, a unique constraint
  original **não bloqueava** nomes de grupo duplicados, ao contrário do
  que o comentário da migration original afirmava. Corrigido com uma
  coluna **gerada pelo próprio banco** (`storedAs('COALESCE(tenant_id, 0)')`),
  `tenant_key`, usada só pela constraint `unique(['tenant_key', 'name'])`
  — por ser calculada pelo banco (não escrita pela aplicação), fica
  correta mesmo para linhas inseridas fora de
  `createGroup()`/`updateGroup()` (fixtures de teste, scripts de
  migração de dados). Uma primeira tentativa de correção usando uma
  coluna comum sincronizada manualmente pela aplicação foi descartada
  depois de um teste de execução mostrar que ela quebrava justamente o
  cenário que deveria continuar funcionando: dois grupos com o mesmo
  nome em tenants diferentes, inseridos diretamente (sem passar pela
  API do pacote).
- `HasRoles::setPrimaryRole()` chamava `$this->authz()->invalidateForUser()`,
  método `protected static` de `Concerns\Cacheable` — inacessível de
  fora da classe. Corrigido para `Authorization::forgetUserCache()`
  (público, desenhado para este caso).
- `HasRoles::getUsersWithRole()` lia `config('auth.providers.users.model')`
  direto, ignorando `config('authz.user_model')` (que não existia ainda
  no config nesta altura da investigação). Corrigido para tentar
  `authz.user_model` primeiro.
- O teste `ConfigurableUserTableTest` usava a chave de config
  `authz.tables.user` (singular) para simular uma tabela de usuários
  renomeada, mas a chave real do pacote é `authz.tables.users` (plural)
  — o teste testava, sem perceber, sempre o valor padrão. Corrigido
  para a chave certa; validado por execução real que a foreign key de
  `auth_groups_users.user_id` de fato aponta para o nome configurado.

### Pendências conhecidas
- Decisão de negócio em aberto: negação individual (`auth_permissions_users`)
  vale cross-tenant ou por tenant? Hoje: global (comportamento original
  preservado).
- Policies não são geradas nem sugeridas pelo pacote — ver
  [seção correspondente do README](README.md#o-que-este-pacote-deliberadamente-não-faz).

## Estratégia de versionamento

`0.x` até validação em um segundo projeto real; a partir daí, semver 1.0.

