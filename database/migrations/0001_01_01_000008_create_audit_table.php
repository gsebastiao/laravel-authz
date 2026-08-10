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
        $audit = config('authz.tables.audit');

        Schema::create($audit, function (Blueprint $table) {
            $table->id();
            $table->uuid('batch')->nullable()->index();
            $table->string('subject_type', 255); // nome da tabela auditada (ex: auth_groups)
            $table->unsignedBigInteger('subject_id');
            $table->string('event', 100); // ex: 'created', 'updated', 'deleted.failed'
            $table->text('changes')->nullable(); // JSON — sempre presente, legível
            $table->text('debug_info')->nullable(); // JSON — só preenchido quando event termina em '.failed'
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('event');
            $table->index('created_at');

            // Sem foreign key para user_id de propósito — mesmo motivo de
            // auth_logins: não perder o registro de auditoria se o usuário
            // for deletado depois.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('authz.tables.audit'));
    }
};
