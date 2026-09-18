# Laravel Authz

[![Latest Version](https://img.shields.io/packagist/v/gsebastiao/laravel-authz.svg)](https://packagist.org/packages/gsebastiao/laravel-authz)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-authz.svg)](composer.json)
[![License](https://img.shields.io/packagist/l/gsebastiao/laravel-authz.svg)](LICENSE.md)

Controle **quem pode fazer o quê** no seu sistema Laravel usando **grupos** e **permissões**.

```php
if ($user->hasPermission('financeiro.aprovar')) {
    // mostra o botão "Aprovar"
}
```

- Coloque usuários em grupos ("Financeiro", "RH"...) e dê permissões aos grupos.
- Dê ou negue uma permissão a um usuário específico, quando precisar de uma exceção.
- Defina validade por data ("tem acesso só até 31/12").
- Funciona com `@can`, `Gate`, Policies e middleware de rota do Laravel.
- Multi-tenant, cache e auditoria são **opcionais** e começam desligados.

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Instalação](#2-instalação)
3. [Primeiros passos (5 minutos)](#3-primeiros-passos-5-minutos)
4. [Onde checar permissões](#4-onde-checar-permissões)
5. [Como a decisão é tomada](#5-como-a-decisão-é-tomada)
6. [Referência rápida](#6-referência-rápida)
7. [Recursos opcionais](#7-recursos-opcionais) — catálogo no config, cache, multi-tenant, auditoria, nomes de tabela
8. [Problemas comuns](#8-problemas-comuns)
9. [Atualizando da versão 2.x](#9-atualizando-da-versão-2x)

---

## 1. Requisitos

- PHP 8.2 ou superior
- Laravel 11, 12 ou 13
- Uma tabela de usuários (a `users` padrão do Laravel serve)

## 2. Instalação

**Passo 1 — instale o pacote:**

```bash
composer require gsebastiao/laravel-authz
```

**Passo 2 — rode o instalador:**

```bash
php artisan authz:install
```

Ele cria o arquivo `config/authz.php`, copia as migrations para
`database/migrations` e pergunta se você quer rodar o `migrate`. São criadas 5
tabelas: `auth_groups`, `auth_groups_users`, `auth_permissions`,
`auth_permissions_groups` e `auth_permissions_users`.

> Sua tabela de usuários não se chama `users`? Responda **não** quando o
> instalador perguntar, ajuste `tables.users` em `config/authz.php` e depois
> rode `php artisan migrate`.

**Passo 3 — adicione o trait no model de usuário:**

```php
// app/Models/User.php
use Gsebastiao\LaravelAuthz\Traits\HasAuthz;

class User extends Authenticatable
{
    use HasAuthz;

    // ...
}
```

Pronto. O pacote já está funcionando.

## 3. Primeiros passos (5 minutos)

Vamos montar um exemplo real: o grupo **Financeiro** pode **ver** e
**aprovar** pagamentos.

### 3.1 Declare as permissões

Em `config/authz.php`:

```php
'permissions' => [
    'financeiro.ver',
    'financeiro.aprovar',
],
```

E rode:

```bash
php artisan authz:sync-permissions
```

> Dica: use o formato `modulo.acao`. O pacote separa o módulo (`financeiro`) e a
> ação (`aprovar`) sozinho. Rode este comando sempre que mudar a lista (ex: no
> deploy) — ele cria as novas, atualiza as alteradas e **nunca apaga nada**.

### 3.2 Crie o grupo e dê as permissões a ele

Num seeder, num `php artisan tinker` ou numa tela de administração:

```php
use Gsebastiao\LaravelAuthz\Facades\Authz;

$financeiro = Authz::createGroup('Financeiro', 'Equipe do financeiro');

Authz::grantPermissionToGroup($financeiro, Authz::resolvePermissionId('financeiro.ver'));
Authz::grantPermissionToGroup($financeiro, Authz::resolvePermissionId('financeiro.aprovar'));
```

### 3.3 Coloque um usuário no grupo

```php
$user = User::find(1);

$user->assignRole('Financeiro');
```

### 3.4 Cheque

```php
$user->hasRole('Financeiro');              // true
$user->hasPermission('financeiro.aprovar'); // true
$user->can('financeiro.aprovar');           // true (integração com o Gate do Laravel)
$user->hasPermission('rh.ver');             // false
```

É isso. Todo o resto do pacote são variações desses quatro passos.

## 4. Onde checar permissões

| Onde | Como |
| --- | --- |
| **Rota** | `Route::get(...)->middleware('authz.permission:financeiro.ver')` |
| **Controller** | `Gate::authorize('financeiro.aprovar');` (erro 403 se não tiver) |
| **Blade** | `@can('financeiro.aprovar') ... @endcan` ou `@hasPermission('financeiro.aprovar') ... @endHasPermission` |
| **Model / Service** | `$user->hasPermission('financeiro.aprovar')` |
| **Policy** | `return $user->hasPermission('financeiro.editar') && $fatura->user_id === $user->id;` |
| **Qualquer lugar** | `Authz::hasPermission('financeiro.aprovar')` (usuário logado) |

### Middleware de rota

```php
// Precisa desta permissão
Route::get('/pagamentos', ...)->middleware('authz.permission:financeiro.ver');

// Precisa de UMA delas (separe com |)
Route::get('/relatorios', ...)->middleware('authz.permission:financeiro.ver|rh.ver');

// Precisa de TODAS (separe com vírgula)
Route::post('/pagamentos/aprovar', ...)->middleware('authz.permission:financeiro.ver,financeiro.aprovar');

// Por grupo, com a mesma sintaxe
Route::get('/diretoria', ...)->middleware('authz.role:Diretoria');
```

Visitante não logado vai para a tela de login; usuário logado sem permissão
recebe erro 403.

### Blade

```blade
@hasPermission('financeiro.aprovar')
    <button>Aprovar</button>
@endHasPermission

@hasAnyPermission(['financeiro.ver', 'rh.ver'])   ... @endHasAnyPermission
@hasAllPermissions(['financeiro.ver', 'rh.ver'])  ... @endHasAllPermissions
@hasRole('Financeiro')                            ... @endHasRole
@hasAnyRole(['Financeiro', 'Diretoria'])          ... @endHasAnyRole
```

As diretivas nativas `@can` / `@cannot` também funcionam.

### Gate e `@can`

O pacote se liga ao Gate do Laravel automaticamente. Suas **Policies e
`Gate::define()` sempre têm prioridade** — o pacote só responde quando nenhuma
regra do seu projeto respondeu. Isso também significa que um "super admin"
via `Gate::before` continua funcionando:

```php
// AppServiceProvider::boot()
Gate::before(fn ($user) => $user->hasRole('Admin') ? true : null);
```

## 5. Como a decisão é tomada

Um usuário recebe permissões de dois lugares: **dos grupos dele** e de
**exceções individuais** (regras só para ele). Quando as regras se
contradizem, vence a primeira linha desta tabela que se aplicar:

| # | Situação | Resultado |
| --- | --- | --- |
| 1 | Exceção individual **negando** | ❌ Negado — nada reverte isso |
| 2 | Exceção individual **concedendo** | ✅ Concedido |
| 3 | Algum grupo **nega de forma absoluta** | ❌ Negado |
| 4 | Algum grupo **concede** | ✅ Concedido |
| 5 | Só há negações comuns de grupo | ❌ Negado |
| 6 | Nenhuma regra | ❌ Negado |

**Na prática:** se ninguém disse nada sobre o usuário, os grupos decidem. Se
nenhum grupo disser nada, a resposta é "não".

**Negação comum x absoluta.** Uma negação comum de grupo perde para a
concessão de outro grupo. Uma negação **absoluta** vence qualquer grupo — use
para regras de compliance ("estagiário nunca aprova pagamento, mesmo que
esteja em outro grupo que aprova"):

```php
Authz::grantPermissionToGroup($estagiarios, $aprovarId, [
    'is_granted' => false,  // nega
    'is_absolute' => true,  // e vence os outros grupos
]);
```

**Só contam regras vigentes.** Uma regra é ignorada se estiver apagada, se a
`start_date` ainda não chegou, se a `end_date` já passou, se o grupo ou a
membresia estiver com `status = 0`, ou se a permissão estiver desativada.

```php
// Acesso temporário: só até o fim do ano
$user->assignRole('Auditoria', ['end_date' => '2026-12-31']);

// Acesso que começa no futuro
$user->grantPermission('financeiro.aprovar', ['start_date' => '2026-10-01']);
```

## 6. Referência rápida

Permissões e grupos podem ser passados **pelo nome** (`'financeiro.aprovar'`,
`'Financeiro'`) **ou pelo id** (`7`). Nomes de permissão não diferenciam
maiúsculas de minúsculas.

### No model User (trait `HasAuthz`)

| Método | O que faz |
| --- | --- |
| `hasPermission($p)` | Tem a permissão? |
| `hasAnyPermission([...])` / `hasAllPermissions([...])` | Tem alguma / todas? |
| `getPermissionNames()` / `getPermissionIds()` | Lista das permissões efetivas |
| `grantPermission($p, $opcoes = [])` | Dá a permissão só para este usuário |
| `denyPermission($p, $opcoes = [])` | Nega a permissão só para este usuário (vence os grupos) |
| `revokePermission($p)` | Retira o que foi dado com `grantPermission` |
| `clearPermissionOverride($p)` | Retira qualquer regra individual (concessão ou negação) |
| `syncPermissions([...])` | Deixa as concessões individuais iguais à lista |
| `hasRole($g)` / `hasAnyRole([...])` / `hasAllRoles([...])` | Está no grupo? |
| `assignRole($g, $opcoes = [])` / `assignRoles([...])` | Coloca no grupo (não duplica) |
| `removeRole($g)` | Tira do grupo |
| `syncRoles([...])` | Deixa o usuário exatamente nos grupos da lista |
| `getRoleNames()` / `getRoleIds()` / `getGroups()` | Grupos atuais |
| `setPrimaryRole($g)` / `getPrimaryRole()` | Grupo principal |
| `User::getUsersWithRole($g)` | Todos os usuários de um grupo |
| `User::whereHasPermission($p)` | Query de usuários com a permissão (sem N+1) |

> Prefere separar? `HasRoles` e `HasPermissions` também existem e podem ser
> usados juntos. `HasAuthz` é só os dois combinados.

### No Facade `Authz` (gerenciar dados)

| Assunto | Métodos |
| --- | --- |
| Grupos | `createGroup($nome, $descricao = null)`, `updateGroup($id, [...])`, `deleteGroup($id)`, `restoreGroup($id)` |
| Membros | `addUserToGroup($userId, $groupId, [...])`, `updateGroupMembership($id, [...])`, `removeUserFromGroup($id)` |
| Permissões | `createPermission($nome, $modulo, $acao, $rotulo)`, `updatePermission($id, [...])`, `deletePermission($id)`, `restorePermission($id)` |
| Regras de grupo | `grantPermissionToGroup($groupId, $permId, [...])`, `updateGroupPermission($id, [...])`, `revokeGroupPermission($id)` |
| Regras individuais | `grantPermissionToUser($userId, $permId, [...])`, `updateUserPermission($id, [...])`, `revokeUserPermission($id)` |
| Consultas | `hasPermission`, `hasRole`, `getEffectivePermissions`, `getUserGroups`, `getAssignablePermissions` |
| Nome → id | `resolvePermissionId('financeiro.aprovar')`, `resolveGroupId('Financeiro')` |
| Cache | `forgetUserCache($userId)`, `flushCache()` |

Comportamentos importantes:

- **`delete*` fazem soft delete** (dá para recuperar com `restore*`). Passe
  `purge: true` para apagar de vez: `Authz::deleteGroup($id, purge: true)`.
- **`grant*` e `addUserToGroup` não duplicam.** Se a regra ou membresia já
  existir, ela é atualizada com as opções informadas.
- **`update*` só aceitam campos conhecidos.** Um campo inválido gera um erro
  que lista os campos aceitos. Retornam `false` quando nada mudou.
- **Erros de uso** (id inexistente, nome duplicado, campo inválido) lançam
  `Gsebastiao\LaravelAuthz\Exceptions\AuthzException` com uma mensagem que
  explica o que fazer.
- Toda escrita roda dentro de uma transação e dispara um evento do Laravel
  (`GroupCreated`, `UserAddedToGroup`, `PermissionGrantedToGroup`... — veja
  `src/Events`).

### Opções aceitas

| Onde | Campos |
| --- | --- |
| Grupo | `name`, `description`, `status` |
| Membresia (`assignRole`, `addUserToGroup`) | `status`, `start_date`, `end_date`, `is_primary`, `observacao` |
| Permissão | `permission`, `module`, `action`, `label`, `description`, `order`, `status`, `tenant_id` |
| Regra de grupo | `is_granted`, `is_absolute`, `start_date`, `end_date` |
| Regra individual | `is_granted`, `start_date`, `end_date` |

### Funções globais

Também existem atalhos globais, úteis em qualquer lugar: `authz()`,
`hasPermission()`, `hasAnyPermission()`, `hasAllPermissions()`, `hasRole()`,
`hasAnyRole()`, `hasAllRoles()`, `getUserGroups()`, `getUserPermissions()`.
Sem `$userId`, usam o usuário logado.

## 7. Recursos opcionais

Nada desta seção é necessário para usar o pacote.

### Catálogo completo no config

Além da forma curta, cada permissão aceita mais detalhes:

```php
'permissions' => [
    'financeiro.ver',
    [
        'permission' => 'financeiro.aprovar',
        'label' => 'Aprovar pagamentos',
        'description' => 'Permite aprovar pagamentos acima de 10 mil',
        'order' => 10,
    ],
],
```

Para montar uma tela de "gerenciar permissões", use
`Authz::getAssignablePermissions()`.

### Cache

Por padrão cada checagem consulta o banco. Em produção, ligue o cache no `.env`:

```env
AUTHZ_CACHE_ENABLED=true
```

Tudo o que é feito pelos métodos do pacote atualiza o cache sozinho. Se você
alterar as tabelas **direto no banco** (SQL, seeder com `DB::table`), rode:

```bash
php artisan authz:cache-reset
```

Outras opções (`AUTHZ_CACHE_STORE`, `AUTHZ_CACHE_TTL`...) estão comentadas em
`config/authz.php`. Validades por data são reavaliadas quando o TTL expira
(padrão: 1 hora).

### Multi-tenant (empresas, filiais, sedes...)

Use só se o sistema for dividido em partes que não podem ver os grupos umas
das outras. Crie uma classe que diga qual é o tenant atual:

```php
namespace App\Support;

use Gsebastiao\LaravelAuthz\Contracts\TenantContext;

class TenantAtual implements TenantContext
{
    public function id(): int|string|null
    {
        return auth()->user()?->empresa_id; // ou sessão, subdomínio...
    }
}
```

E aponte para ela em `config/authz.php`:

```php
'tenant_context' => \App\Support\TenantAtual::class,
```

O que muda:

- **Grupos** passam a pertencer a um tenant. Pode existir um "Financeiro" em
  cada empresa, cada um com suas permissões. `createGroup()` usa o tenant atual.
- **Permissões** continuam globais, a não ser que você crie uma exclusiva:
  `Authz::createPermission('exportar.massa', 'relatorios', 'exportar', 'Exportar', tenantId: 3)`.
  Permissões exclusivas ficam invisíveis e sem efeito nos outros tenants, e
  não podem ser dadas a grupos de outro tenant.
- Nomes de grupo (`assignRole('Financeiro')`) são procurados só no tenant atual.

> Atenção: o id do tenant nunca pode ser `0` nem string vazia.
>
> Os métodos de gerenciamento não verificam se o id que você passou pertence
> ao tenant atual. Numa tela de administração por tenant, valide isso no seu
> controller antes de chamar `updateGroup`, `deleteGroup` etc.

### Auditoria

Com o pacote `gsebastiao/laravel-auditable` instalado e
`AUTHZ_AUDIT_ENABLED=true`, toda alteração feita pelo pacote é auditada.

```php
Authz::getAuditTrail('groups', $groupId); // histórico de um grupo

// "Criado por / em" numa listagem, sem N+1:
Authz::applyAuditJoins('groups', DB::table('auth_groups'))->get();
```

Sem o pacote de auditoria, tudo funciona normalmente, apenas sem registrar.

### Nomes de tabela diferentes

Edite `tables` em `config/authz.php` **antes** de rodar o `migrate`. Mudar
depois não renomeia tabelas já criadas.

## 8. Problemas comuns

**`Grupo 'X' não encontrado`** — use o nome exatamente como foi cadastrado
(incluindo maiúsculas). Com multi-tenant, o grupo precisa ser do tenant atual.

**`Permissão 'x' não encontrada`** — rode `php artisan authz:sync-permissions`
depois de declarar a permissão no config.

**Dei a permissão mas `hasPermission` continua `false`.** Verifique, nesta ordem:
1. O usuário tem uma **negação individual**? (`denyPermission` vence tudo)
2. Algum grupo dele tem **negação absoluta** para essa permissão?
3. A regra, o grupo ou a membresia está com `status = 0`, `start_date` no
   futuro ou `end_date` no passado?
4. A permissão está desativada ou é exclusiva de outro tenant?
5. Cache ligado e você alterou o banco direto? Rode `php artisan authz:cache-reset`.

**`@can('financeiro.aprovar')` retorna `false` mesmo com a permissão.** Veja
se o seu projeto tem um `Gate::define('financeiro.aprovar', ...)` ou uma
Policy respondendo antes — eles têm prioridade. Confirme também que
`gates.auto_register` está `true` em `config/authz.php`.

**`Já existe um grupo apagado com o nome...`** — nomes são únicos mesmo depois
do soft delete. Use `Authz::restoreGroup($id)` (ou `restorePermission`) como a
mensagem indica.

**Rodei `vendor:publish` duas vezes.** Sem problema: migrations já publicadas
não são duplicadas.

## 9. Atualizando da versão 2.x

A 2.x não conseguia ser instalada numa aplicação nova (o `migrate` falhava e as
migrations não eram publicadas corretamente). Se você contornou isso
manualmente e já tem as tabelas:

1. **Não** publique as migrations de novo — as tabelas não mudaram.
2. Se usava `HasRoles` e `HasPermissions` juntos, agora funciona; ou troque os
   dois por `HasAuthz`.
3. Revise o [CHANGELOG](CHANGELOG.md) — algumas regras passaram a ser
   aplicadas como sempre foram documentadas (grupo inativo e `start_date`
   futura deixam de conceder, por exemplo).
4. Opcional: nas instalações novas, apagar um usuário apaga as membresias dele
   em cascata. Se quiser o mesmo numa instalação antiga, troque a foreign key
   `auth_groups_users.user_id` para `ON DELETE CASCADE` numa migration sua.

---

## Testes

```bash
composer test
```

A suíte (85 testes) roda com SQLite em memória e cobre a cascata de decisão,
datas de validade, tenant, cache, auditoria, os traits do model, Gate,
middlewares, Blade e os comandos artisan. Validada em Laravel 11, 12 e 13.

## Licença

[MIT](LICENSE.md)
