# Laravel Authorization

[![Latest Version](https://img.shields.io/packagist/v/gsebastiao/laravel-authz.svg)](https://packagist.org/packages/gsebastiao/laravel-authz)
[![License](https://img.shields.io/packagist/l/gsebastiao/laravel-authz.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-authz.svg)](composer.json)

RBAC (grupos, permissões, cascata de prioridade) para Laravel — com escopo
de multi-tenant, auditoria e cache, todos opcionais e desligados por padrão.
Instale e comece a checar permissões em minutos; ligue tenant, auditoria ou
cache só quando (e se) o projeto realmente precisar.

## Índice

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Início rápido](#início-rápido)
- [Como funciona](#como-funciona)
- [Nomes de tabela customizados](#nomes-de-tabela-customizados)
- [Formato do retorno de permissões](#formato-do-retorno-de-permissões)
- [CRUD](#crud)
- [Auditoria (via laravel-auditable)](#auditoria-via-laravel-auditable)
- [Gates, Middleware e Blade](#gates-middleware-e-blade)
- [Filtrar listagens por permissão (whereHasPermission)](#filtrar-listagens-por-permissão-wherehaspermission)
- [Sincronizar o catálogo de permissões via config](#sincronizar-o-catálogo-de-permissões-via-config)
- [Cache](#cache)
- [Escopo de tenant](#escopo-de-tenant-sede-delegação-departamento-agência)
- [O que este pacote deliberadamente não faz](#o-que-este-pacote-deliberadamente-não-faz)
- [Perguntas frequentes](#perguntas-frequentes)
- [Testes](#testes)
- [Versionamento](#versionamento)

## Requisitos

- PHP `^8.2`
- Laravel `^12.0` ou `^13.0`
- Uma tabela `users` (ou equivalente — [ver abaixo](#nomes-de-tabela-customizados)) já existente no projeto

## Instalação

```bash
composer require gsebastiao/laravel-authz
```

```bash
php artisan vendor:publish --tag=authz-config
```

```bash
php artisan migrate
```

Isso cria 5 tabelas: `auth_groups`, `auth_groups_users`,
`auth_permissions`, `auth_permissions_groups`, `auth_permissions_users`. O
pacote não cria a tabela `users` — assume que ela já existe no projeto host.
Auditoria (`auth_audit_table` e afins) é responsabilidade do pacote
`gsebastiao/laravel-auditable`, instalado à parte quando necessário.

## Início rápido

```php
use Gsebastiao\LaravelAuthz\Models\Authorization;

$auth = new Authorization();

// Criar um grupo e conceder uma permissão a ele
$permId = $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar solicitação');
$groupId = $auth->createGroup('financeiro', 'Time financeiro');
$auth->grantPermissionToGroup($groupId, $permId);

// Colocar um usuário no grupo
$auth->addUserToGroup($userId, $groupId);

// Checar
$auth->hasPermission('financeiro.aprovar', $userId); // true
```

Isso é o suficiente pra a maioria dos projetos. Tenant, cache e detalhes da
auditoria são para quando o projeto crescer — nada disso precisa ser
configurado agora.

## Como funciona

Duas ideias carregam o pacote inteiro. Vale entender as duas antes do resto.

**1. O usuário sempre tem a última palavra.** As permissões efetivas de um
usuário vêm de duas fontes — o que foi concedido/negado a ele
individualmente, e o que os grupos dele concedem/negam — e a ordem de
prioridade é fixa:

| # | Fonte | Resultado |
| --- | --- | --- |
| 1 | Negação individual no usuário | **Negado**, sempre — nenhum grupo reverte isso |
| 2 | Concessão individual no usuário | **Concedido**, sempre |
| 3 | Negação *absoluta* de algum grupo do usuário | **Negado** — vence concessão de outro grupo |
| 4 | Concessão de algum grupo do usuário | **Concedido** |
| 5 | Só há negação *não-absoluta* de grupo | **Negado** |
| 6 | Nada em lugar nenhum | **Negado** (padrão) |

Regra prática: se ninguém disse nada sobre o usuário especificamente, os
grupos dele decidem. Se nem os grupos disserem nada, a resposta é negado.
"Negação absoluta" (`is_absolute = true` na concessão do grupo) existe para
regras que nenhum outro grupo do usuário pode driblar — ex: uma regra de
compliance que vence mesmo se o usuário também estiver num grupo que
concede.

**2. Tenant é opcional e começa desligado.** Sem nenhuma configuração, o
pacote se comporta como se o projeto tivesse um usuário só — não existe
partição nenhuma. Se um dia o projeto precisar de Sede, Empresa, Escola,
Filial ou qualquer outro nível de particionamento, existe um ponto de
extensão pronto — [ver a seção de tenant](#escopo-de-tenant-sede-delegação-departamento-agência)
— sem precisar reescrever nada do que já existe.

## Nomes de tabela customizados

Se o projeto já usa `auth_groups`, `auth_permissions`, `users` etc. para
outra coisa, publique e edite o config antes de rodar a migration:

```bash
php artisan vendor:publish --tag=authz-config
```

```bash
php artisan vendor:publish --tag=authz-migrations
```

```php
// config/authz.php
'tables' => [
    'groups' => 'meu_nome_de_grupos',
    'user' => 'usuarios', // tabela de usuários do projeto, se não for 'users'
    // ...
],
```

Editar o config depois de rodar a migration não renomeia tabelas já
criadas — o config precisa refletir os nomes finais antes do `migrate`.

## Formato do retorno de permissões

`getEffectivePermissions()` é o único método de leitura de dados de
permissão do pacote. O chamador escolhe o formato via
`Gsebastiao\LaravelAuthz\Enums\PermissionFormat`:

```php
use Gsebastiao\LaravelAuthz\Enums\PermissionFormat;

// Padrão — id e permission juntos:
// [['id' => 3, 'permission' => 'financeiro.aprovar'], ...]
$auth->getEffectivePermissions($userId);

// Só os ids: [3, 7, 12]
$auth->getEffectivePermissions($userId, PermissionFormat::Id);

// Só as strings: ['financeiro.aprovar', 'usuario.criar']
$auth->getEffectivePermissions($userId, PermissionFormat::Permission);
```

`hasPermission()` aceita id (int) ou nome (string) — o tipo do argumento
decide a comparação:

```php
$auth->hasPermission('financeiro.aprovar', $userId); // por nome
$auth->hasPermission(7, $userId);                    // por id
```

Uma string numérica (`'7'`) continua sendo tratada como nome, nunca como id
— só um `int` literal ativa a busca por id.

`getUserGroups($userId)` retorna os grupos ativos do usuário —
`[['id' => 1, 'name' => 'financeiro', 'description' => '...'], ...]`.

## CRUD

Toda escrita nas 5 tabelas mutáveis (`auth_groups`, `auth_groups_users`,
`auth_permissions`, `auth_permissions_groups`, `auth_permissions_users`)
passa por uma função de CRUD dedicada, sempre dentro de uma transação de
banco (com ou sem auditoria ligada — ver seção seguinte), e dispara um
Event do Laravel próprio por operação (`GroupCreated`, `GroupUpdated`,
`PermissionGrantedToGroup`, etc. — 16 classes em `Events\`).

```php
$auth = new Authorization();

// Grupos
$groupId = $auth->createGroup('financeiro', 'Time financeiro');
$auth->updateGroup($groupId, ['description' => 'nova descrição']);
$auth->deleteGroup($groupId);              // soft delete
$auth->deleteGroup($groupId, purge: true); // exclusão física

// Membros de grupo
$membershipId = $auth->addUserToGroup($userId, $groupId);
$auth->updateGroupMembership($membershipId, ['is_primary' => 1]);
$auth->removeUserFromGroup($membershipId);

// Catálogo de permissões
$permId = $auth->createPermission('financeiro.aprovar', 'financeiro', 'aprovar', 'Aprovar');
$auth->updatePermission($permId, ['label' => 'Aprovar solicitação']);
$auth->deletePermission($permId);

// Concessão por grupo — valida tenant antes de gravar, ver seção de tenant
$grantId = $auth->grantPermissionToGroup($groupId, $permId);
$auth->updateGroupPermission($grantId, ['is_absolute' => true]);
$auth->revokeGroupPermission($grantId);

// Exceção individual
$overrideId = $auth->grantPermissionToUser($userId, $permId, ['is_granted' => false]);
$auth->updateUserPermission($overrideId, ['end_date' => '2026-12-31']);
$auth->revokeUserPermission($overrideId);
```

Todo `updateX`/`deleteX`/`revokeX`/`removeX` lança `\RuntimeException`
explícita se o id não existir — nunca falha silenciosamente retornando
`false`.

`createGroup()` deriva `tenant_id` sempre do tenant ativo — não é parâmetro
aceito, para não abrir uma forma de criar um grupo apontando pra outro
tenant por engano. `createPermission()` é o oposto: `tenant_id` é parâmetro
explícito e `null` por padrão (global), porque a maioria das permissões deve
ser global — exclusiva de um tenant é a exceção deliberada.

`grantPermissionToGroup()` valida, antes de gravar, que a permissão é
visível ao tenant do grupo (global, ou exclusiva do mesmo tenant). Falha
nessa validação dispara o evento `PermissionGrantToGroupRejected`, grava
`grant.rejected` na auditoria (se ligada) e lança `\RuntimeException` —
nada é gravado no banco.

## Auditoria (via laravel-auditable)

Este pacote não tem motor de auditoria próprio — quando
`gsebastiao/laravel-auditable` está instalado **e** `authz.audit.enabled`
está ligado, toda função de CRUD acima grava automaticamente através dele.
Sem o pacote instalado, ou com a opção desligada, tudo funciona
normalmente (mesmas transações, mesmos Events) só que sem gravar nada de
auditoria — nunca lança erro por falta do pacote opcional.

```bash
composer require gsebastiao/laravel-auditable
```

```php
// config/authz.php
'audit' => [
    'enabled' => env('AUTHZ_AUDIT_ENABLED', false),
],
```

```php
// Trilha de auditoria de um registro específico — chave LÓGICA de
// tabela ('groups', não 'auth_groups'), resolvida internamente
$auth->getAuditTrail('groups', $groupId);
```

### Como a resolução condicional funciona

Os 5 models do pacote (`Group`, `Permission`, `GroupUser`,
`PermissionGroup`, `PermissionUser`) usam
`Gsebastiao\LaravelAuthz\Concerns\ResolvedAuditableTrait` — um alias
resolvido uma única vez por processo, antes de qualquer model carregar
(via `composer.json > autoload > files`), para o trait real do
laravel-auditable quando ele existe, ou para um trait vazio
(`NoOpAuditable`) caso contrário. Não é possível aplicar uma trait a uma
classe já declarada — por isso a resolução acontece cedo, via
`class_alias()`, e não dentro de um `boot()` de ServiceProvider.

### Colunas de "criado por / em" em listagens

`applyAuditJoins()` anexa colunas a uma query via `LEFT JOIN`, sem
consulta N+1 — no-op transparente (retorna a query como veio) se a
auditoria não estiver ativa:

```php
use Illuminate\Support\Facades\DB;

$query = DB::table('auth_groups')->where('status', 1);
$auth->applyAuditJoins('groups', $query)->get();
```

`$events` (terceiro parâmetro) sobrescreve
`config('authz.audit.join_events')` (padrão `['created', 'updated']`).

### Coluna de rótulo legível configurável

Os `changes` de concessões incluem um rótulo além do id (ex:
`{"group": {"id": 3, "label": "Financeiro"}}`). A coluna lida vem de
`config('authz.audit.label_columns.{tabela}')` — troque `name`/`permission`
por outro nome se o projeto usar outra convenção (`nome`, `label`...).

## Gates, Middleware e Blade

Com `config('authz.gates.auto_register')` ligado (padrão), o
ServiceProvider registra um `Gate::define()` por permissão do catálogo,
uma vez no boot da aplicação:

```php
Gate::allows('financeiro.aprovar'); // true/false, mesma lógica de hasPermission()
```

Middleware, para proteger rotas por permissão:

```php
Route::post('/aprovar', ...)->middleware('authz.permission:financeiro.aprovar');
```

Blade directives:

```blade
@hasPermission('financeiro.aprovar')
    <button>Aprovar</button>
@endHasPermission

@hasAnyPermission(['financeiro.ver', 'financeiro.aprovar'])
    ...
@endHasAnyPermission

@hasRole('financeiro')
    ...
@endHasRole
```

Helpers globais equivalentes, para uso fora de views
(`hasPermission()`, `hasAnyPermission()`, `hasAllPermissions()`,
`hasRole()`, `hasAnyRole()`, `hasAllRoles()`, `getUserGroups()`,
`getUserPermissions()`) — todos aceitam `$userId` opcional, com
`Auth::id()` como padrão.

## Filtrar listagens por permissão (whereHasPermission)

Para telas de listagem que precisam filtrar usuários por permissão sem
N+1 (uma query por usuário candidato), a macro `whereHasPermission`
replica a mesma cascata de precedência de `hasPermission()` via
`whereExists`/`whereNotExists`:

```php
User::whereHasPermission('financeiro.aprovar')->get();

// Se a coluna de id do usuário não se chamar 'id' na tabela consultada:
User::whereHasPermission('financeiro.aprovar', 'usuario_id')->get();
```

## Sincronizar o catálogo de permissões via config

Para projetos que preferem declarar permissões no código em vez de
gerenciá-las manualmente via banco:

```php
// config/authz.php
'permissions' => [
    [
        'permission' => 'financeiro.aprovar',
        'module' => 'financeiro',
        'action' => 'aprovar',
        'label' => 'Aprovar solicitação financeira',
    ],
    // ...
],
```

```bash
php artisan authz:sync-permissions
```

Cria o que falta, atualiza os campos que mudaram no que já existe, e
nunca deleta automaticamente — permissões que saíram do config mas ainda
existem no banco são listadas como aviso, para revisão manual.

## Cache

Desligado por padrão. Ligue quando o volume de acessos simultâneos tornar
"consultar o banco a cada checagem de permissão" um gargalo real — não
antes disso.

```bash
# .env
AUTHZ_CACHE_ENABLED=true
```

```php
// config/authz.php — todos os parâmetros, com o default de cada um
'cache' => [
    'enabled' => env('AUTHZ_CACHE_ENABLED', false),
    'store' => env('AUTHZ_CACHE_STORE', null),               // null = driver padrão da aplicação
    'ttl' => env('AUTHZ_CACHE_TTL', 3600),                    // segundos
    'prefix' => env('AUTHZ_CACHE_PREFIX', 'authz'),
    'invalidate_on_write' => env('AUTHZ_CACHE_INVALIDATE_ON_WRITE', true),
],
```

`getUserGroups()` e `getEffectivePermissions()` são os dois métodos
cacheados — os realmente quentes em produção (`hasPermission()` chama
`getEffectivePermissions()` a cada checagem, então herda o cache
automaticamente). Os três formatos de `getEffectivePermissions()`
compartilham uma única entrada de cache por usuário/tenant — pedir `Id`
numa chamada e `Permission` na próxima não gera duas consultas.

`store: null` usa o driver de cache padrão do projeto
(`config('cache.default')`) — o pacote nunca fala com Redis, Memcached ou
qualquer driver diretamente, sempre através do `Cache` facade do Laravel,
que sabe conversar com qualquer um deles. Funciona com `file` (padrão do
Laravel), `database`, `redis`, `memcached`, `array`, `dynamodb` — qualquer
store que o projeto já tenha configurado em `config/cache.php`. Para usar um
store diferente do padrão só para este pacote, aponte `'store' => 'redis'`.

### Invalidação automática

As funções de CRUD invalidam o cache certo sozinhas — não precisa chamar
nada manualmente no caminho comum:

| Ação | Invalida |
| --- | --- |
| `addUserToGroup`, `removeUserFromGroup`, `updateGroupMembership` | o usuário específico |
| `grantPermissionToUser`, `updateUserPermission`, `revokeUserPermission` | o usuário específico |
| `grantPermissionToGroup`, `updateGroupPermission`, `revokeGroupPermission` | todo membro atual do grupo |
| `updateGroup`, `deleteGroup` | todo membro atual do grupo |

**Exceção deliberada:** `updatePermission()`/`deletePermission()` (editar o
catálogo em si) **não invalidam automaticamente**. Descobrir precisamente
quem tem aquela permissão concedida — por qualquer grupo, de qualquer
tenant, ou por exceção individual — exigiria cruzar 3 tabelas para uma ação
de admin rara. Se isso importar para o seu caso, chame manualmente:

```php
Authorization::forgetUserCache($userId);
```

`invalidate_on_write: false` desliga a invalidação automática das funções
de CRUD (troca correção imediata por menos trabalho a cada escrita — a
leitura volta a ficar correta quando o TTL expirar). `forgetUserCache()`
continua funcionando manualmente mesmo com isto desligado.

## Escopo de tenant (Sede, Delegação, Departamento, Agência...)

O pacote não sabe, e não precisa saber, que nome de negócio o seu
particionamento tem. Ele só entende `TenantContext::id()`: um id, ou `null`
para "sem particionamento".

Padrão de fábrica: `NullTenantContext`, sempre `null` — nenhum filtro é
aplicado em lugar nenhum, comportamento idêntico a um projeto sem tenant.

Para ativar, implemente o contrato:

```php
use Gsebastiao\LaravelAuthz\Contracts\TenantContext;

class TenantFromUser implements TenantContext
{
    public function id(): int|string|null
    {
        return auth()->user()?->tenant_id; // ou sessão, subdomínio, header, claim de token...
    }
}
```

E aponte para ela em `config/authz.php`:

```php
'tenant_context' => \App\Support\TenantFromUser::class,
```

A partir daí, toda vez que o pacote precisar saber "qual é o tenant agora",
ele pede a interface — e o Laravel entrega a sua classe, por causa do bind
já feito no service provider do pacote. Trocar de volta pro padrão é
remover essa linha do config, nada no resto do código muda.

**O que fica escopado:** `auth_groups` (grupos/papéis) — um grupo
"Financeiro" pode existir uma vez por tenant, com permissões diferentes em
cada um. O escopo chega às tabelas de vínculo de forma transitiva, via
`group_id`.

**O que fica global por padrão, mas pode ser exclusivo de um tenant:**
`auth_permissions`. Preencha `tenant_id` numa permissão pra torná-la
exclusiva:

```php
$auth->createPermission('exportar.massa', 'relatorios', 'exportar', 'Exportar em massa', tenantId: $tenantId);
```

Isso importa especialmente porque, se cada tenant tem sua própria tela de
gerenciar grupos/permissões (autoatendimento, não centralizado por você),
uma permissão exclusiva precisa ficar **invisível** para outros tenants, não
só inacessível — senão o gestor de outro tenant vê a opção no seletor e
consegue concedê-la a si mesmo. Duas proteções cobrem isso:

- `getAssignablePermissions()` — o que a tela de gerenciar grupos de cada
  tenant deve consultar para montar o seletor — só retorna permissões
  globais mais as exclusivas do tenant ativo.
- `getEffectivePermissions()`/`hasPermission()` ignoram qualquer concessão
  de grupo cuja permissão não seja visível ao tenant ativo, mesmo que a
  concessão exista no banco — defesa em profundidade para o caso de um
  grant indevido ter sido criado por bug ou edição direta.
- `grantPermissionToGroup()` valida a mesma regra **antes** de gravar,
  impedindo a linha ruim de existir, não só ignorando-a depois.

`permission` continua globalmente único mesmo com `tenant_id` preenchido —
dois tenants não conseguem reivindicar a mesma string, mesmo para
permissões exclusivas.

**Atenção:** o id do tenant precisa ser um valor truthy (nunca `0`).

**Detalhe de schema:** `auth_groups` tem uma coluna **gerada pelo banco**
`tenant_key` (`COALESCE(tenant_id, 0)`, nunca escrita pela aplicação),
usada só pela constraint `unique(['tenant_key', 'name'])`. Isso existe
porque `NULL` não é igual a `NULL` em `UNIQUE` na maioria dos bancos
(SQLite, PostgreSQL, SQL Server — MySQL é exceção) — com `tenant_id`
sempre `null` no modo padrão, `unique(['tenant_id', 'name'])` direto não
impediria nomes de grupo duplicados. Por ser calculada pelo próprio
banco, fica correta mesmo para linhas inseridas fora de
`createGroup()`/`updateGroup()` (fixtures de teste, scripts de migração
de dados) — nunca defina `tenant_key` manualmente.

## O que este pacote deliberadamente não faz

- Menus, navegação ou árvore de UI — fica em um pacote separado, que
  consome os ids retornados por `getEffectivePermissions()`.
- Hierarquia de escopo (Sede contém Departamento, com herança de permissão
  descendo a árvore).
- Múltiplos eixos simultâneos de tenant (Sede E Departamento ao mesmo
  tempo, com regra de precedência entre os dois).
- Models de domínio `Sede`/`Delegação`/`Departamento`/`Agência` — isso é
  modelagem do projeto host; o pacote termina no `tenant_id` genérico.
- Laravel Policies — Policies são tipicamente por Model de domínio do
  projeto host (`PostPolicy`, `InvoicePolicy`...), não deste pacote. Use
  os Gates auto-registrados (`Gate::allows('financeiro.aprovar')`) ou
  `hasPermission()` dentro da própria Policy do seu projeto, quando
  precisar combinar a checagem de permissão com regras específicas do
  domínio (ex: "só pode editar a própria fatura E ter
  `financeiro.editar`").

Se um caso real precisar de algo disso, desenhe para aquele caso concreto —
mais barato e mais certeiro do que generalizar sem um caso para guiar o
design.

## Perguntas frequentes

**Meu catálogo de permissões é global — não posso ter uma funcionalidade
exclusiva de um tenant?** Pode. "Global" é sobre o catálogo (quais strings
o código conhece), não sobre quem pode *usar* cada uma — isso é decidido
pela concessão, que já é tenant-scoped via grupo. Se precisar que a
permissão fique **invisível** para outros tenants (não só inacessível),
[ver acima](#escopo-de-tenant-sede-delegação-departamento-agência).

**`TenantContext` só serve para "Sede"?** Não — o nome vem só de um
exemplo. O contrato entende um id genérico; o que ele representa
(Delegação, Escola, Filial, Empresa) é decisão do projeto host, não do
pacote.

**Por que `TenantContext` é uma interface e não um valor de config?**
Porque "qual é o tenant agora" é lógica (vem de sessão, subdomínio, coluna
no usuário — varia por projeto), não um dado fixo. Uma string de config não
executa código; uma classe sim.

**Meu sistema tem vários níveis (Departamento dentro de Delegação, Filial
dentro de Sede) — como uso isso?** Pergunte: em qual desses níveis um
GRUPO precisa ter identidade própria (papéis diferentes por nível)? Na
maioria dos casos, é só um nível — os outros são classificação de dado, não
precisam passar por `TenantContext`. Aponte `TenantContext.id()` para esse
nível único. Se genuinamente precisar de herança entre dois níveis, isso é
uma extensão maior que o pacote não cobre hoje — ver seção acima.

**Preciso ligar cache pra este pacote funcionar?** Não. Desligado por
padrão, e a maioria dos projetos nunca vai precisar ligar.

**Editei uma permissão do catálogo com cache ligado — os usuários que a
têm ficam com dado desatualizado?** Só até o TTL expirar —
`updatePermission()`/`deletePermission()` não invalidam automaticamente
(ver [seção de cache](#invalidação-automática)). Chame
`Authorization::forgetUserCache($userId)` se precisar de correção
imediata.

## Testes

```bash
composer test
```

Cobrem as 6 branches da cascata de prioridade, os três formatos de
`getEffectivePermissions()`, comportamento idêntico ao single-tenant sem
`TenantContext` customizado, isolamento real entre tenants, permissões
exclusivas de tenant (seletor filtrado e defesa em leitura e escrita), nomes
de tabela customizados fim a fim, CRUD auditado (diff correto, caminho de
falha, agrupamento por transação), `applyAuditJoins()`, e cache (servindo do
cache de verdade, invalidação por usuário e por grupo, isolamento por
tenant, toggle `invalidate_on_write`, formatos compartilhando uma entrada).

Além da suíte PHPUnit, o comportamento também foi validado por execução
real (SQLite in-memory) durante o desenvolvimento — incluindo os cenários
mais delicados de precedência de `whereHasPermission()` contra
`hasPermission()` como baseline, rollback real de transação forçando
violação de chave, e os dois cenários de integração com
`gsebastiao/laravel-auditable` (instalado e não instalado).

## Versionamento

Este pacote está em `0.1.x`: a API pode mudar sem aviso até ser validada em
pelo menos um projeto real além do de origem. Depois disso, semver 1.0
convencional.

## Licença

[MIT](LICENSE.md)
