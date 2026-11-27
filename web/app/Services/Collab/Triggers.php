<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite triggers that note every change to a replicated table in
 * collab_dirty. They stay quiet while remote changes are written
 * (collab_ctx.applying = 1) and while no project is collaborative.
 * (Re)created after every migration run on the laptop edition.
 */
final class Triggers
{
    private const WHEN = "(SELECT applying FROM collab_ctx WHERE id = 1) = 0 AND EXISTS (SELECT 1 FROM collab_projects WHERE status = 'active')";

    public static function install(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('collab_dirty') || ! Schema::hasTable('collab_ctx')) {
            return;
        }
        SyncSchema::reset();
        self::uninstall();
        foreach (SyncSchema::tables() as $tbl => $def) {
            foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $event => $ref) {
                $pk = implode(" || '|' || ", array_map(fn ($c) => "{$ref}.\"{$c}\"", (array) $def['pk']));
                $name = 'collab_'.strtolower($event).'_'.$tbl;
                DB::unprepared("CREATE TRIGGER \"{$name}\" AFTER {$event} ON \"{$tbl}\" WHEN ".self::WHEN
                    ." BEGIN INSERT OR IGNORE INTO collab_dirty (tbl, pk) VALUES ('{$tbl}', {$pk}); END");
            }
        }
        // a converted text changes its source's file hash
        if (Schema::hasTable('source_statuses')) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $name = 'collab_'.strtolower($event).'_source_statuses';
                DB::unprepared("CREATE TRIGGER \"{$name}\" AFTER {$event} ON source_statuses WHEN ".self::WHEN
                    ." BEGIN INSERT OR IGNORE INTO collab_dirty (tbl, pk) VALUES ('sources', NEW.source_id); END");
            }
        }
    }

    public static function uninstall(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'collab\\_%' ESCAPE '\\'") as $t) {
            DB::unprepared('DROP TRIGGER IF EXISTS "'.$t->name.'"');
        }
    }
}
