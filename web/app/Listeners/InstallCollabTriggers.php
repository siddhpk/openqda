<?php

namespace App\Listeners;

use App\Services\Collab\Triggers;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;

/**
 * Laptop edition: after the launcher's "migrate" on every start, (re)create
 * the capture triggers for the replicated tables that exist now, and keep
 * SQLite in write-ahead-log mode (the node agent's requests write alongside
 * the browser's). Nothing happens on a server.
 */
class InstallCollabTriggers
{
    public function handle(MigrationsEnded $event): void
    {
        if (! config('desktop.enabled') || DB::getDriverName() !== 'sqlite') {
            return;
        }
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            DB::select('PRAGMA journal_mode=WAL');
        }
        Triggers::install();
    }
}
