<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $logins = config('authz.tables.logins');
        $groups = config('authz.tables.groups');
        $groupsUsers = config('authz.tables.groups_users');
        $users = config('authz.tables.user');

        // Table: auth_logins
        Schema::create($logins, function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 255);
            $table->string('user_agent', 255)->nullable();
            $table->string('id_type', 255); // 'username', 'email', etc.
            $table->string('identifier', 255); // o valor digitado (ex: joao@email.com)
            $table->unsignedBigInteger('user_id')->nullable(); // apenas logins bem-sucedidos
            $table->datetime('date');
            $table->boolean('success'); // 0 = falha, 1 = sucesso

            $table->timestamps(); // created_at e updated_at (automáticos)
            $table->softDeletes(); // deleted_at (para soft deletes)

            // Índices para consultas rápidas
            $table->index(['id_type', 'identifier']);
            $table->index('user_id');
            $table->index('date');
            $table->index('ip_address');

            // NOTA: Não usar foreign key para user_id
            // Para não perder o registro se o usuário for deletado
        });

        // Tabela: auth_groups
        Schema::create($groups, function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('status')->default(1);

            // Emenda de tenant: nullable, indexado, sempre null enquanto
            // nenhum TenantContext customizado for configurado. Com
            // tenant_id sempre null, a unique(['tenant_id','name'])
            // abaixo se comporta exatamente como unique(['name'])
            // sozinho — nenhuma mudança de comportamento hoje.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->timestamps(); // created_at e updated_at (automáticos)
            $table->softDeletes(); // deleted_at (para soft deletes)

            $table->index('status');
            $table->unique(['tenant_id', 'name']);
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });

        // Tabela: auth_groups_users
        // Suporta múltiplos grupos por usuário (ex: Director + Financeiro),
        // com is_primary indicando qual grupo é o principal.
        Schema::create($groupsUsers, function (Blueprint $table) use ($groups, $users) {
            $table->id(); // id int UNSIGNED NOT NULL (auto-increment)

            $table->foreignId('user_id')->constrained($users)->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('group_id')->constrained($groups)->cascadeOnUpdate()->restrictOnDelete();
            $table->tinyInteger('status')->unsigned()->default(1); // status tinyint UNSIGNED DEFAULT '1'
            $table->date('data_inicio'); // data_inicio date NOT NULL
            $table->date('data_fim')->nullable(); // data_fim date DEFAULT NULL
            $table->tinyInteger('is_primary')->unsigned()->default(0); // qual grupo é o principal do usuário
            $table->text('observacao')->nullable(); // observacao text DEFAULT NULL

            $table->timestamps(); // created_at e updated_at (automáticos)
            $table->softDeletes(); // deleted_at (para soft deletes)

            // Índices recomendados para performance
            $table->index('user_id');
            $table->index('group_id');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('is_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('authz.tables.groups_users'));
        Schema::dropIfExists(config('authz.tables.groups'));
        Schema::dropIfExists(config('authz.tables.logins'));
    }
};
