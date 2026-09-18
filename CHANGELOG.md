# Changelog

Todas as mudanças relevantes deste pacote. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versões seguem
[SemVer](https://semver.org/lang/pt-BR/).

## [3.0.0] - 2026-09-18

Versão focada em fazer o pacote **instalar e funcionar de ponta a ponta** numa
aplicação nova, corrigir regras de permissão que não eram aplicadas e
simplificar a documentação. Validada em Laravel 11, 12 e 13 (85 testes).

### Corrigido — instalação

- **`php artisan migrate` (e qualquer comando artisan) falhava numa aplicação
  nova** com `no such table: auth_permissions`: o registro de Gates consultava
  o banco durante o boot, antes das tabelas existirem. Agora a integração com
  o Gate não faz nenhuma consulta no boot.
- **`vendor:publish --tag=authz-migrations` copiava os `.stub` sem timestamp
  e sem extensão `.php`**, e o `migrate` os ignorava — nenhuma tabela era
  criada. A correção da 2.0.1 não resolvia (`publishesMigrations()` não
  renomeia arquivos). Agora cada stub é publicado como
  `AAAA_MM_DD_HHMMSS_create_authz_*_tables.php`, na ordem certa, e publicar
  de novo não duplica.
- **A suíte de testes não rodava** (0 de 46): mesmos dois motivos acima.
- **Usar `HasRoles` e `HasPermissions` juntos causava erro fatal** (os dois
  definiam `authz()`).

### Corrigido — regras de permissão

- Grupo com `status = 0` continuava concedendo permissões.
- `start_date` era ignorada: regras e membresias com início no futuro já
  valiam.
- Com uma concessão e uma negação individuais para a mesma permissão, o
  resultado dependia da ordem das linhas no banco. A negação sempre vence.
- Concessão individual de uma permissão exclusiva de outro tenant era
  honrada (a de grupo já não era).
- `whereHasPermission()` podia discordar de `hasPermission()`: ignorava
  permissão desativada/apagada, `status` do grupo, `start_date`, maiúsculas e
  deixava a concessão individual vencer a negação individual.
- Middleware `authz.permission:a,b` checava só a primeira permissão. Agora
  `a,b` exige todas e `a|b` exige uma delas.

### Corrigido — gerenciamento

- Conceder de novo uma permissão a um grupo depois de revogá-la violava a
  UNIQUE `(group_id, permission_id)`.
- `authz:sync-permissions` falhava quando uma permissão do config estava
  apagada (soft delete). Agora ela é recuperada.
- `deleteGroup(purge: true)` falhava se o grupo tivesse membros, e não
  limpava o cache dos ex-membros.
- `updatePermission()` e `deletePermission()` não limpavam o cache.
- `update*()` aceitavam qualquer coluna (`id`, `tenant_key`, `user_id`...).
- Invalidação de cache baseada em "registro de chaves" podia perder chaves
  quando o driver descartava entradas. Substituída por invalidação por versão.
- Traits do model User:
  - `removeRole()` causava erro fatal (`stdClass` usado como array).
  - `assignRole('x')` inexistente mostrava `Role '' not found`.
  - `revokePermission()` apagava também **negações** — o usuário podia
    ganhar acesso ao "revogar".
  - `syncPermissions()` comparava com as permissões herdadas dos grupos.
  - `setPrimaryRole()` removia o grupo principal antes de validar o novo, e
    mexia em membresias apagadas e de outros tenants.
  - Busca de grupo por nome ignorava o tenant ativo (podia usar o grupo de
    outra empresa).
  - `syncRoles()` ignorava em silêncio nomes de grupo inexistentes.
- Diretivas Blade dependiam das funções globais `hasPermission()`/`hasRole()`,
  que podem ter sido definidas por outro pacote.
- Testes com `/** @test */` são ignorados no PHPUnit 12; migrados para
  `#[Test]`. `ConfigurableUserTableTest` estava no arquivo de outra classe e
  nunca rodava.

### Adicionado

- `php artisan authz:install` — publica config e migrations e roda o migrate.
- `php artisan authz:cache-reset` e `Authz::flushCache()`.
- Trait `HasAuthz` (grupos + permissões em um só `use`).
- No model: `hasAnyPermission`, `hasAllPermissions`, `denyPermission`,
  `clearPermissionOverride`, `directPermissionIds`, `hasAnyRole`,
  `hasAllRoles`. Métodos aceitam nome ou id.
- No serviço: `hasAnyPermission`, `hasAllPermissions`, `hasRole`,
  `hasAnyRole`, `hasAllRoles`, `restoreGroup`, `restorePermission`,
  `resolveGroupId`, `resolvePermissionId`.
- Middleware `authz.role`.
- Diretivas `@hasAllPermissions` e `@hasAnyRole`.
- `Gsebastiao\LaravelAuthz\Exceptions\AuthzException` (estende
  `\RuntimeException`) com mensagens que dizem o que fazer.
- `authz:sync-permissions` aceita a forma curta `'modulo.acao'` e os campos
  opcionais `order`, `status` e `tenant_id`.

### Alterado (pode exigir ajuste)

- **Gate:** em vez de um `Gate::define()` por permissão no boot, o pacote usa
  `Gate::after()`. Policies e `Gate::define()` do projeto agora têm
  prioridade; `Gate::has('x')` não lista mais as permissões.
- **`grant*()` e `addUserToGroup()` não duplicam:** se a regra/membresia já
  existir, ela é atualizada e o mesmo id é retornado.
- **`update*()`** lançam `AuthzException` para campos não permitidos e
  retornam `false` quando nada mudou (nada é gravado nem auditado).
- **`createGroup()`/`createPermission()`** lançam `AuthzException` com nome
  duplicado (antes: erro do banco). A auditoria de falha continua registrada.
- **Migrations** renomeadas para `create_authz_groups_tables` e
  `create_authz_permissions_tables`. Em instalações novas, apagar um usuário
  ou um grupo apaga as membresias em cascata (antes: bloqueava).
- `Models\Authorization` não estende mais `Eloquent\Model` (é um serviço, não
  uma tabela).
- `HasPermissions::getEffectivePermissions()` aceita também o enum
  `PermissionFormat`.
- `hasRole()` global aceita id (int) além do nome.

## [2.0.1] - 2026-09-18

### Corrigido
- Tentativa de corrigir a publicação de migrations trocando `publishes()` por
  `publishesMigrations()` (não resolveu — ver 3.0.0).

## [2.0.0] - 2026-09-17

### Adicionado
- Os 15 métodos de CRUD em `Authorization`, todos em transação e com eventos
  (16 classes em `src/Events`).
- Auditoria opcional via `gsebastiao/laravel-auditable`
  (`getAuditTrail()`, `applyAuditJoins()`).
- Gates, middleware `authz.permission`, macro `whereHasPermission`, diretivas
  Blade e o comando `authz:sync-permissions`.
- Permissões exclusivas de tenant (`auth_permissions.tenant_id`) e
  `getAssignablePermissions()`.
- Cache opcional de grupos e permissões.

### Alterado
- **BREAKING:** coluna gerada `auth_groups.tenant_key` para garantir nome de
  grupo único por tenant.
- **BREAKING:** middleware movido para `Http/Middleware/PermissionMiddleware.php`.
- `getEffectivePermissions()` recebe `PermissionFormat` (`Id`,
  `Permission`, `Both`); `hasPermission()` aceita id ou nome.

### Corrigido
- Erro fatal de sintaxe nos 5 models.
- Defesa de tenant desligada quando não havia tenant ativo.
- Coluna ambígua em `whereHasPermission()`.
- Foreign keys de `user_id` presas a `users` em vez do nome configurado.

### Removido
- `HierarchicalTenantContext` e os helpers globais `can()`/`cannot()`.

## [1.0.0] - 2026-08-10

- Versão inicial.

[3.0.0]: https://github.com/gsebastiao/laravel-authz/compare/v2.0.1...v3.0.0
[2.0.1]: https://github.com/gsebastiao/laravel-authz/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/gsebastiao/laravel-authz/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/gsebastiao/laravel-authz/releases/tag/v1.0.0
