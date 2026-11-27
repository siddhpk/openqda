<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entry point of the laptop collaboration module: whether it is active,
 * this node's identity, and the mapping between local projects and their
 * collaboration (global) ids.
 */
final class Collab
{
    private static ?NodeIdentity $identity = null;

    private static array $projectGids = [];

    private static ?bool $hasTables = null;

    /** Active only in the laptop edition (SQLite, started by the launcher). */
    public static function enabled(): bool
    {
        return (bool) config('desktop.enabled')
            && (string) config('collab.node_key') !== ''
            && function_exists('sodium_crypto_sign_seed_keypair')
            && DB::getDriverName() === 'sqlite'
            && (self::$hasTables ??= Schema::hasTable('collab_projects'));
    }

    public static function identity(): NodeIdentity
    {
        $seed = (string) config('collab.node_key');
        if (self::$identity === null || self::$identity->seed !== $seed) {
            self::$identity = NodeIdentity::fromSeed($seed);
        }

        return self::$identity;
    }

    public static function node(): string
    {
        return self::identity()->node;
    }

    /** Forget cached values (tests, and after leaving or joining a project). */
    public static function reset(): void
    {
        self::$projectGids = [];
        self::$hasTables = null;
        IdMap::reset();
        Roster::forget();
        SyncSchema::reset();
    }

    public static function projectGid(int|string|null $projectId): ?string
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }
        $key = (string) $projectId;
        if (! array_key_exists($key, self::$projectGids)) {
            self::$projectGids[$key] = DB::table('collab_projects')
                ->where('project_id', $projectId)->where('status', 'active')->value('gid');
        }

        return self::$projectGids[$key];
    }

    public static function projectId(string $gid): ?int
    {
        $id = DB::table('collab_projects')->where('gid', $gid)->where('status', 'active')->value('project_id');

        return $id === null ? null : (int) $id;
    }

    public static function project(string $gid): ?object
    {
        return DB::table('collab_projects')->where('gid', $gid)->first();
    }

    public static function isCollaborative(int|string|null $projectId): bool
    {
        return self::enabled() && self::projectGid($projectId) !== null;
    }

    /** @return array<int, object> active collaborations */
    public static function activeProjects(): array
    {
        return DB::table('collab_projects')->where('status', 'active')->whereNotNull('project_id')->get()->all();
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        $value = DB::table('collab_settings')->where('key', $key)->value('value');

        return $value === null ? $default : $value;
    }

    public static function putSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            DB::table('collab_settings')->where('key', $key)->delete();

            return;
        }
        DB::table('collab_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    public static function secret(string $key): ?string
    {
        $value = self::setting($key);
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function putSecret(string $key, ?string $value): void
    {
        self::putSetting($key, $value === null || $value === '' ? null : Crypt::encryptString($value));
    }

    /** The name collaborators see for this computer. */
    public static function displayName(): string
    {
        $name = self::setting('name');
        if ($name) {
            return $name;
        }
        $first = DB::table('users')->where('email', 'not like', '%@openqda.invalid')->orderBy('id')->value('name');

        return $first ?: (gethostname() ?: 'OpenQDA');
    }

    /** A stable color for a person, from their global id. */
    public static function color(string $gid): string
    {
        $palette = ['#2563eb', '#db2777', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#dc2626', '#4d7c0f', '#c026d3', '#0f766e'];

        return $palette[crc32($gid) % count($palette)];
    }
}
