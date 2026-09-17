<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nomes de tabela
    |--------------------------------------------------------------------------
    |
    | Sobrescreva aqui se o projeto host já usa esses nomes para outra
    | coisa (ex: outro pacote de permissões já instalado). O pacote lê
    | esses nomes tanto no código quanto nas migrations publicadas — não
    | existe nome de tabela hardcoded em nenhum outro lugar.
    |
    */
    'tables' => [
        'groups' => 'auth_groups',
        'permissions' => 'auth_permissions',
        'groups_users' => 'auth_groups_users',
        'permissions_users' => 'auth_permissions_users',
        'permissions_groups' => 'auth_permissions_groups',

        // Tabela de usuários do projeto host — o pacote nunca cria essa
        // tabela, só referencia via foreign key. 'users' é o padrão do
        // Laravel; troque aqui se o projeto usa outro nome (ex: 'usuarios').
        'users' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auditoria (via laravel-auditable)
    |--------------------------------------------------------------------------
    |
    | Se o pacote gsebastiao/laravel-auditable estiver instalado e esta opção
    | estiver habilitada, todos os CRUDs do authz serão auditados automaticamente.
    |
    */
    'audit' => [
        'enabled' => env('AUTHZ_AUDIT_ENABLED', false),

        // Prefixo de coluna usado por applyAuditJoins() para as colunas
        // {prefixo}{evento}_at / {prefixo}{evento}_by anexadas via LEFT JOIN.
        // Default 'audit_' porque 'created'/'updated' são nomes de
        // evento aqui — sem prefixo, as colunas geradas colidiriam com
        // created_at/updated_at nativos da própria tabela.
        'column_prefix' => env('AUTHZ_AUDIT_COLUMN_PREFIX', 'audit_'),

        // Eventos usados por padrão em applyAuditJoins() quando $events
        // não é passado explicitamente.
        'join_events' => ['created', 'updated'],

        // Coluna usada como rótulo legível de cada tabela mutável, para
        // trocar uma FK crua (group_id: 3) por um valor legível
        // (group: {id: 3, label: "Financeiro"}) no changes de auditoria.
        'label_columns' => [
            'groups' => 'name',
            'permissions' => 'permission',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model de usuário
    |--------------------------------------------------------------------------
    |
    | Usado pelas relações user() de GroupUser/PermissionUser, e como
    | fallback preferencial em HasRoles::getUsersWithRole() antes de
    | cair em config('auth.providers.users.model'). Deixe null para usar
    | sempre o model de usuário padrão configurado em config/auth.php.
    |
    */
    'user_model' => env('AUTHZ_USER_MODEL', null),

    /*
    |--------------------------------------------------------------------------
    | Gates
    |--------------------------------------------------------------------------
    |
    | Se true, o ServiceProvider registra automaticamente um
    | Gate::define() por permissão do catálogo (uma vez no boot),
    | nomeado igual à própria string de permissão.
    |
    */
    'gates' => [
        'auto_register' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo declarativo de permissões
    |--------------------------------------------------------------------------
    |
    | Usado por `php artisan authz:sync-permissions` para criar/atualizar
    | o catálogo de permissões a partir do código, em vez de gerenciar
    | manualmente via banco. Cada entrada segue a assinatura de
    | createPermission(): 'permission', 'module', 'action', 'label', e
    | opcionalmente 'description'.
    |
    | Exemplo:
    | [
    |     'permission' => 'financeiro.aprovar',
    |     'module' => 'financeiro',
    |     'action' => 'aprovar',
    |     'label' => 'Aprovar solicitação financeira',
    |     'description' => null,
    | ],
    |
    */
    'permissions' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Cacheia getUserGroups() e getEffectivePermissions() — os dois
    | métodos de leitura realmente quentes em produção (hasPermission()
    | chama getEffectivePermissions() a cada checagem de permissão, então
    | herda o cache automaticamente).
    |
    | 'enabled' vem desligado por padrão, e a leitura é via env — ligar em
    | produção sem tocar em código publicado é 'AUTHZ_CACHE_ENABLED=true'
    | no .env. Nada aqui força um driver específico: 'store' aponta pra
    | qualquer connection já configurada em config/cache.php do projeto
    | (file, database, redis, memcached, array, dynamodb...); null usa o
    | driver padrão da aplicação. O pacote nunca fala com Redis/Memcached
    | diretamente — sempre através do Cache facade do Laravel, que é quem
    | sabe conversar com qualquer driver.
    |
    | 'invalidate_on_write' controla só a invalidação AUTOMÁTICA disparada
    | pelas funções de CRUD do pacote — desligar troca correção imediata
    | por menos trabalho de invalidação a cada escrita (a leitura volta a
    | ficar correta assim que o TTL expirar). forgetUserCache() continua
    | funcionando manualmente mesmo com isto desligado.
    |
    */
    'cache' => [
        'ttl' => env('AUTHZ_CACHE_TTL', 3600),
        'store' => env('AUTHZ_CACHE_STORE', null),
        'enabled' => env('AUTHZ_CACHE_ENABLED', false),
        'prefix' => env('AUTHZ_CACHE_PREFIX', 'authz'),
        'invalidate_on_write' => env('AUTHZ_CACHE_INVALIDATE_ON_WRITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contexto de tenant
    |--------------------------------------------------------------------------
    |
    | Binding padrão: NullTenantContext, que sempre retorna null — ou
    | seja, comportamento single-tenant idêntico ao pacote sem nenhum
    | particionamento.
    |
    | Projetos que precisarem de Sede, Delegação, Departamento, Agência
    | ou qualquer outro particionamento implementam a própria classe
    | satisfazendo Gsebastiao\LaravelAuthz\Contracts\TenantContext e
    | apontam para ela aqui. O pacote não sabe, e não precisa saber, o
    | nome de negócio desse particionamento — só entende um id (ou null).
    |
    | Atenção: o id do tenant precisa ser um valor truthy (nunca 0, nunca
    | string vazia). O filtro de escopo usa Illuminate\Query\Builder::when(),
    | que trata valores falsy como "sem tenant ativo".
    |
    */
    'tenant_context' => \Gsebastiao\LaravelAuthz\Support\NullTenantContext::class,

];
