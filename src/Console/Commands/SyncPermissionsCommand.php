<?php

namespace Gsebastiao\LaravelAuthz\Console\Commands;

use Gsebastiao\LaravelAuthz\Models\Authorization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan authz:sync-permissions
 *
 * Lê a lista 'permissions' de config/authz.php e deixa o banco igual:
 * cria as que faltam, atualiza as que mudaram e recupera as que estavam
 * apagadas. Nunca apaga nada: permissões que estão no banco mas não no
 * config só são listadas como aviso.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'authz:sync-permissions';

    protected $description = 'Sincroniza as permissões de config/authz.php com o banco';

    /** Campos opcionais que só são comparados quando aparecem no config. */
    private const OPTIONAL = ['order', 'status', 'tenant_id'];

    public function handle(Authorization $authz): int
    {
        $existing = DB::table(config('authz.tables.permissions'))->get()->keyBy('permission');
        $counts = ['criadas' => 0, 'atualizadas' => 0, 'recuperadas' => 0];
        $declared = [];

        foreach ((array) config('authz.permissions', []) as $entry) {
            $entry = is_string($entry) ? ['permission' => $entry] : (array) $entry;
            $name = trim((string) ($entry['permission'] ?? ''));

            if ($name === '') {
                $this->components->warn('Entrada sem a chave "permission" ignorada.');
                continue;
            }

            $declared[] = $name;
            [$module, $action] = $this->guessModuleAndAction($name);
            $target = [
                'module' => $entry['module'] ?? $module,
                'action' => $entry['action'] ?? $action,
                'label' => $entry['label'] ?? $name,
                'description' => $entry['description'] ?? null,
            ] + array_intersect_key($entry, array_flip(self::OPTIONAL));

            $row = $existing->get($name);

            if (!$row) {
                $id = $authz->createPermission($name, $target['module'], $target['action'], $target['label'],
                    $target['description'], $entry['tenant_id'] ?? null);
                $extra = array_intersect_key($target, array_flip(['order', 'status']));
                if ($extra) {
                    $authz->updatePermission($id, $extra);
                }
                $counts['criadas']++;
                $this->components->info("Criada: {$name}");
                continue;
            }

            if ($row->deleted_at !== null) {
                $authz->restorePermission((int) $row->id);
                $counts['recuperadas']++;
                $this->components->info("Recuperada: {$name}");
            }

            $changed = array_filter($target, fn($value, $field) => (string) ($row->{$field} ?? '') !== (string) ($value ?? ''), ARRAY_FILTER_USE_BOTH);

            if ($changed) {
                $authz->updatePermission((int) $row->id, $changed);
                $counts['atualizadas']++;
                $this->components->info("Atualizada: {$name} (" . implode(', ', array_keys($changed)) . ')');
            }
        }

        $orphans = $existing->filter(fn($row) => $row->deleted_at === null)->keys()->diff($declared);

        if ($orphans->isNotEmpty()) {
            $this->components->warn('No banco mas não no config (nada foi apagado, revise manualmente):');
            $orphans->each(fn($name) => $this->line("  - {$name}"));
        }

        $this->components->info(sprintf(
            'Concluído: %d criada(s), %d atualizada(s), %d recuperada(s), %d fora do config.',
            $counts['criadas'], $counts['atualizadas'], $counts['recuperadas'], $orphans->count()
        ));

        return self::SUCCESS;
    }

    /** 'financeiro.aprovar' -> ['financeiro', 'aprovar'] */
    private function guessModuleAndAction(string $name): array
    {
        $pos = strrpos($name, '.');

        return $pos === false ? [$name, $name] : [substr($name, 0, $pos), substr($name, $pos + 1)];
    }
}
