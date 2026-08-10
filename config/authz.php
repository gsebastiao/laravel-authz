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
        'logins' => 'auth_logins',
        'groups' => 'auth_groups',
        'groups_users' => 'auth_groups_users',
        'permissions' => 'auth_permissions',
        'permissions_groups' => 'auth_permissions_groups',
        'permissions_users' => 'auth_permissions_users',
        'audit' => 'auth_audit_table',

        // Tabela de usuários do projeto host — o pacote nunca cria essa
        // tabela, só referencia via foreign key. 'users' é o padrão do
        // Laravel; troque aqui se o projeto usa outro nome (ex: 'usuarios').
        'user' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auditoria
    |--------------------------------------------------------------------------
    |
    | 'enabled' desliga toda gravação de auditoria (as funções de CRUD
    | continuam funcionando normalmente, só não gravam nada em
    | auth_audit_table). 'required', quando true, faz uma falha ao gravar
    | a própria auditoria lançar exceção (além de logar); quando false, só
    | loga e segue — a operação de negócio não é afetada por uma falha na
    | auditoria em si.
    |
    | 'join_events' é o default de applyAuditJoins() quando o chamador não
    | passa uma lista própria — quais eventos viram colunas '{evento}_at'/
    | '{evento}_by' numa query de listagem. Qualquer string de evento que
    | o pacote grava funciona aqui (created, updated, deleted, purged,
    | granted, revoked...), não só os 4 do padrão de referência.
    |
    | 'column_prefix' evita colisão com colunas nativas da própria tabela
    | — 'created' e 'updated' são nomes de evento aqui, então sem prefixo
    | 'created_at'/'updated_at' colidiriam de verdade com as colunas
    | nativas de timestamp.
    |
    | 'user_label_column' é a coluna de auth.tables.user exibida como
    | '{evento}_by' nas colunas de applyAuditJoins().
    |
    | 'label_columns' é a coluna exibida como rótulo legível nos changes
    | da auditoria (ex: no lugar de só group_id: 3, mostra também o nome
    | do grupo) — por tabela do pacote, editável se o projeto usa outro
    | nome de coluna (ex: 'nome' em vez de 'name').
    |
    */
    'audit' => [
        'enabled' => true,
        'required' => true,
        'join_events' => ['created', 'updated'],
        'column_prefix' => 'audit_',
        'user_label_column' => 'name',
        'label_columns' => [
            'groups' => 'name',
            'permissions' => 'permission',
        ],
    ],

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
        'enabled' => env('AUTHZ_CACHE_ENABLED', false),
        'store' => env('AUTHZ_CACHE_STORE', null),
        'ttl' => env('AUTHZ_CACHE_TTL', 3600),
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
