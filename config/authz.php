<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nomes das tabelas
    |--------------------------------------------------------------------------
    |
    | Só mude se o seu projeto já usa algum destes nomes, ou se a sua tabela
    | de usuários não se chama "users". Mude ANTES de rodar o migrate.
    |
    */
    'tables' => [
        'groups' => 'auth_groups',
        'groups_users' => 'auth_groups_users',
        'permissions' => 'auth_permissions',
        'permissions_groups' => 'auth_permissions_groups',
        'permissions_users' => 'auth_permissions_users',

        // Tabela de usuários do SEU projeto (o pacote não cria esta tabela).
        'users' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model de usuário
    |--------------------------------------------------------------------------
    |
    | null = usa o model de config/auth.php (normalmente App\Models\User).
    |
    */
    'user_model' => env('AUTHZ_USER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Integração com o Gate do Laravel
    |--------------------------------------------------------------------------
    |
    | true = @can('financeiro.aprovar'), Gate::allows(...), $user->can(...),
    | $this->authorize(...) e o middleware 'can:' passam a entender as
    | permissões deste pacote. Suas Policies e Gates continuam tendo
    | prioridade. Não faz nenhuma consulta ao banco durante o boot.
    |
    */
    'gates' => [
        'auto_register' => env('AUTHZ_GATES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de permissões (opcional)
    |--------------------------------------------------------------------------
    |
    | Declare aqui as permissões do sistema e rode:
    |     php artisan authz:sync-permissions
    |
    | Forma curta (módulo e ação são deduzidos do nome):
    |     'financeiro.aprovar',
    |
    | Forma completa:
    |     [
    |         'permission' => 'financeiro.aprovar',
    |         'label' => 'Aprovar pagamentos',
    |         'module' => 'financeiro',      // opcional
    |         'action' => 'aprovar',         // opcional
    |         'description' => null,         // opcional
    |         'order' => 10,                 // opcional
    |     ],
    |
    */
    'permissions' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache (desligado por padrão)
    |--------------------------------------------------------------------------
    |
    | Guarda os grupos e as permissões de cada usuário para não consultar o
    | banco a cada checagem. Recomendado em produção: AUTHZ_CACHE_ENABLED=true
    |
    | Tudo o que é feito pelos métodos do pacote limpa o cache sozinho.
    | Mexeu direto no banco? Rode: php artisan authz:cache-reset
    |
    */
    'cache' => [
        'enabled' => env('AUTHZ_CACHE_ENABLED', false),
        'store' => env('AUTHZ_CACHE_STORE'),            // null = cache padrão do projeto
        'ttl' => env('AUTHZ_CACHE_TTL', 3600),           // segundos
        'prefix' => env('AUTHZ_CACHE_PREFIX', 'authz'),
        'invalidate_on_write' => env('AUTHZ_CACHE_INVALIDATE_ON_WRITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenant (opcional)
    |--------------------------------------------------------------------------
    |
    | Deixe como está se o sistema não é dividido em empresas/filiais/sedes.
    | Para ativar, crie uma classe que implemente
    | Gsebastiao\LaravelAuthz\Contracts\TenantContext e aponte para ela aqui.
    | O id do tenant nunca pode ser 0 nem string vazia.
    |
    */
    'tenant_context' => \Gsebastiao\LaravelAuthz\Support\NullTenantContext::class,

    /*
    |--------------------------------------------------------------------------
    | Auditoria (opcional, requer gsebastiao/laravel-auditable)
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => env('AUTHZ_AUDIT_ENABLED', false),

        // Prefixo das colunas {prefixo}created_at / {prefixo}created_by
        // adicionadas por applyAuditJoins().
        'column_prefix' => env('AUTHZ_AUDIT_COLUMN_PREFIX', 'audit_'),

        // Eventos usados por applyAuditJoins() quando nenhum é informado.
        'join_events' => ['created', 'updated'],

        // Coluna usada como "nome legível" de cada tabela nos registros de auditoria.
        'label_columns' => [
            'groups' => 'name',
            'permissions' => 'permission',
        ],
    ],

];
