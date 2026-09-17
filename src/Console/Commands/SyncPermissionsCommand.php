<?php

namespace Gsebastiao\LaravelAuthz\Console\Commands;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza o catálogo declarativo de permissões (config('authz.permissions'))
 * com a tabela auth_permissions: cria o que falta, atualiza os campos
 * que mudaram no que já existe, e nunca deleta automaticamente — só
 * lista os "órfãos" (existem no banco mas saíram do config) como aviso,
 * para revisão manual.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'authz:sync-permissions';
    protected $description = 'Sincroniza o catálogo de permissões de config(authz.permissions) com o banco';

    public function handle(Authorization $authz): int
    {
        $declared = config('authz.permissions', []);
        $table = config('authz.tables.permissions', 'auth_permissions');

        $existing = DB::table($table)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('permission');

        $created = 0;
        $updated = 0;
        $declaredNames = [];

        foreach ($declared as $entry) {
            $name = $entry['permission'] ?? null;

            if (!$name) {
                $this->components->warn('Entrada de config(authz.permissions) sem chave "permission" ignorada.');
                continue;
            }

            $declaredNames[] = $name;
            $row = $existing->get($name);

            $target = [
                'module' => $entry['module'] ?? '',
                'action' => $entry['action'] ?? '',
                'label' => $entry['label'] ?? $name,
                'description' => $entry['description'] ?? null,
            ];

            if (!$row) {
                $authz->createPermission(
                    permission: $name,
                    module: $target['module'],
                    action: $target['action'],
                    label: $target['label'],
                    description: $target['description'],
                    tenantId: $entry['tenant_id'] ?? null,
                );

                $created++;
                $this->components->info("Criada: {$name}");

                continue;
            }

            $changed = [];
            foreach ($target as $field => $value) {
                if ((string) ($row->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changed[$field] = $value;
                }
            }

            if (!empty($changed)) {
                $authz->updatePermission($row->id, $changed);
                $updated++;
                $this->components->info("Atualizada: {$name} (" . implode(', ', array_keys($changed)) . ')');
            }
        }

        $orphans = $existing->keys()->diff($declaredNames);

        if ($orphans->isNotEmpty()) {
            $this->components->warn('Permissões no banco que não estão mais no config (revisão manual, nada foi apagado):');
            foreach ($orphans as $orphan) {
                $this->components->warn("  - {$orphan}");
            }
        }

        $this->components->info("Sincronização concluída: {$created} criada(s), {$updated} atualizada(s), {$orphans->count()} órfã(s).");

        return self::SUCCESS;
    }
}
