<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Which tables are replicated and how their rows are translated between
 * this laptop's ids and the global ids (gids) every laptop shares.
 *
 * Per table (in dependency order, parents first):
 *   pk       primary key column (or columns for link tables)
 *   int      the primary key is an auto-increment number (mapped to a uuid)
 *   project  how to find the project: 'self', a column, or [column, parent table]
 *   fk       column => table it refers to ('users' are mapped to shadow users)
 *   poly     column => [type column, [type => table]] for polymorphic refs
 *   natural  columns whose values identify the row on every laptop (the
 *            gid is derived from them, so concurrent creations merge)
 *   unique   unique column sets (a collision merges the rows)
 *   filter   [column, value] rows replicated only when column == value
 *   exclude  local-only columns; 'only' lists the replicated ones instead
 *
 * Tables of optional modules (Analysis Toolkit) count only when they exist.
 * Never replicated: users, teams, audits, settings, preferences, AI data,
 * source_statuses (rebuilt), revision snapshots.
 */
final class SyncSchema
{
    private const NAMESPACE = '6f1c8a52-3d1e-4c47-9a8f-5b2f0c1e9d70';

    private const TABLES = [
        'projects' => ['pk' => 'id', 'int' => true, 'project' => 'self', 'only' => ['name', 'description', 'origin']],
        'codebooks' => ['pk' => 'id', 'int' => true, 'project' => 'project_id',
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users']],
        'codes' => ['pk' => 'id', 'project' => ['codebook_id', 'codebooks'],
            'fk' => ['codebook_id' => 'codebooks', 'parent_id' => 'codes']],
        'sources' => ['pk' => 'id', 'project' => 'project_id', 'files' => true, 'exclude' => ['upload_path', 'rich_text_path'],
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users', 'modifying_user_id' => 'users']],
        'variables' => ['pk' => 'id', 'project' => ['source_id', 'sources'], 'natural' => ['source_id', 'name'],
            'fk' => ['source_id' => 'sources', 'project_id' => 'projects']],
        'selections' => ['pk' => 'id', 'project' => 'project_id',
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users', 'modifying_user_id' => 'users',
                'code_id' => 'codes', 'source_id' => 'sources']],
        'notes' => ['pk' => 'id', 'int' => true, 'project' => 'project_id', 'filter' => ['visibility', 1],
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users'],
            'poly' => ['target' => ['type', ['project' => 'projects', 'source' => 'sources', 'selection' => 'selections',
                'code' => 'codes', 'case' => 'cases', 'relation' => 'code_relations', 'concept' => 'concepts',
                'proposition' => 'propositions']]]],
        'code_relations' => ['pk' => 'id', 'project' => 'project_id', 'unique' => [['project_id', 'dedupe_key']],
            'fk' => ['project_id' => 'projects', 'from_code_id' => 'codes', 'to_code_id' => 'codes', 'creating_user_id' => 'users']],
        'concepts' => ['pk' => 'id', 'project' => 'project_id', 'unique' => [['project_id', 'name']],
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users', 'updated_by' => 'users']],
        'concept_code' => ['pk' => ['concept_id', 'code_id'], 'project' => ['concept_id', 'concepts'], 'natural' => ['concept_id', 'code_id'],
            'fk' => ['concept_id' => 'concepts', 'code_id' => 'codes']],
        'propositions' => ['pk' => 'id', 'project' => 'project_id',
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users', 'updated_by' => 'users']],
        'proposition_concept' => ['pk' => ['proposition_id', 'concept_id'], 'project' => ['proposition_id', 'propositions'],
            'natural' => ['proposition_id', 'concept_id'], 'fk' => ['proposition_id' => 'propositions', 'concept_id' => 'concepts']],
        'cases' => ['pk' => 'id', 'project' => 'project_id', 'unique' => [['project_id', 'name']],
            'fk' => ['project_id' => 'projects', 'creating_user_id' => 'users']],
        'case_source' => ['pk' => ['case_id', 'source_id'], 'project' => ['case_id', 'cases'], 'natural' => ['case_id', 'source_id'],
            'fk' => ['case_id' => 'cases', 'source_id' => 'sources']],
        'case_assessments' => ['pk' => 'id', 'int' => true, 'project' => 'project_id', 'natural' => ['case_id', 'item_type', 'item_id'],
            'fk' => ['project_id' => 'projects', 'case_id' => 'cases', 'updated_by' => 'users'],
            'poly' => ['item_id' => ['item_type', ['code' => 'codes', 'concept' => 'concepts', 'proposition' => 'propositions']]]],
        'analytic_evidence' => ['pk' => 'id', 'project' => 'project_id', 'unique' => [['target_type', 'target_id', 'selection_id']], 'nullable_fk' => ['selection_id'],
            'fk' => ['project_id' => 'projects', 'selection_id' => 'selections', 'source_id' => 'sources', 'creating_user_id' => 'users'],
            'poly' => ['target_id' => ['target_type', ['concept' => 'concepts', 'proposition' => 'propositions']]]],
        'project_vocabularies' => ['pk' => 'id', 'int' => true, 'project' => 'project_id', 'natural' => ['project_id'],
            'fk' => ['project_id' => 'projects', 'updated_by' => 'users']],
        'research_designs' => ['pk' => 'id', 'int' => true, 'project' => 'project_id', 'natural' => ['project_id'],
            'fk' => ['project_id' => 'projects', 'updated_by' => 'users']],
    ];

    /** Texts that can be co-edited live: table => [field => column] ('html' is the source's file). */
    public const DOCS = ['notes' => ['content' => 'content'], 'sources' => ['html' => null]];

    private static array $exists = [];

    private static array $columns = [];

    public static function reset(): void
    {
        self::$exists = [];
        self::$columns = [];
    }

    /** @return array<string, array> existing replicated tables, parents first */
    public static function tables(): array
    {
        return array_filter(self::TABLES, fn ($def, $tbl) => self::exists($tbl), ARRAY_FILTER_USE_BOTH);
    }

    public static function exists(string $tbl): bool
    {
        return self::$exists[$tbl] ??= Schema::hasTable($tbl);
    }

    public static function has(string $tbl): bool
    {
        return isset(self::TABLES[$tbl]) && self::exists($tbl);
    }

    public static function def(string $tbl): array
    {
        return self::TABLES[$tbl] ?? throw new \InvalidArgumentException("Table {$tbl} is not replicated.");
    }

    public static function order(string $tbl): int
    {
        $i = array_search($tbl, array_keys(self::TABLES), true);

        return $i === false ? 999 : $i;
    }

    /** @return string[] */
    public static function pkCols(string $tbl): array
    {
        return (array) self::def($tbl)['pk'];
    }

    public static function composite(string $tbl): bool
    {
        return is_array(self::def($tbl)['pk']);
    }

    /** @return string[] replicated columns */
    public static function fields(string $tbl): array
    {
        $def = self::def($tbl);
        $cols = self::$columns[$tbl] ??= Schema::getColumnListing($tbl);
        if (isset($def['only'])) {
            return array_values(array_intersect($cols, $def['only']));
        }
        $skip = array_merge(self::pkCols($tbl), $def['exclude'] ?? [], ['updated_at']);

        return array_values(array_diff($cols, $skip));
    }

    public static function pkString(string $tbl, array $row): string
    {
        return implode('|', array_map(fn ($c) => (string) $row[$c], self::pkCols($tbl)));
    }

    /** @return array<string, string> column => value of a primary key string */
    public static function pkValues(string $tbl, string $pk): array
    {
        $cols = self::pkCols($tbl);
        $parts = count($cols) > 1 ? explode('|', $pk, count($cols)) : [$pk];

        return count($parts) === count($cols) ? array_combine($cols, $parts) : [];
    }

    public static function query(string $tbl, string $pk)
    {
        return DB::table($tbl)->where(self::pkValues($tbl, $pk));
    }

    public static function load(string $tbl, string $pk): ?array
    {
        $values = self::pkValues($tbl, $pk);
        if (! $values) {
            return null;
        }
        $row = DB::table($tbl)->where($values)->first();

        return $row ? (array) $row : null;
    }

    /** The local project id a row belongs to. */
    public static function projectIdOf(string $tbl, array $row): ?int
    {
        $how = self::def($tbl)['project'];
        if ($how === 'self') {
            return (int) $row['id'];
        }
        if (is_string($how)) {
            $value = $row[$how] ?? null;
            if ($value === null && $tbl === 'variables' && ! empty($row['source_id'])) {
                $value = DB::table('sources')->where('id', $row['source_id'])->value('project_id');
            }

            return $value === null || $value === '' ? null : (int) $value;
        }
        [$col, $parent] = $how;
        if (empty($row[$col])) {
            return null;
        }
        $parentRow = self::load($parent, (string) $row[$col]);

        return $parentRow ? self::projectIdOf($parent, $parentRow) : null;
    }

    public static function replicable(string $tbl, array $row): bool
    {
        $filter = self::def($tbl)['filter'] ?? null;

        return $filter === null || (string) ($row[$filter[0]] ?? '') === (string) $filter[1];
    }

    /** @return string[] local primary keys of a project's rows */
    public static function rowsOfProject(string $tbl, int $projectId): array
    {
        $def = self::def($tbl);
        $how = $def['project'];
        $cols = self::pkCols($tbl);
        if ($how === 'self') {
            return [(string) $projectId];
        }
        if (is_string($how)) {
            $q = DB::table($tbl)->where($how, $projectId);
            if ($tbl === 'variables') {
                $q = DB::table($tbl)->where(fn ($w) => $w->where('project_id', (string) $projectId)
                    ->orWhereIn('source_id', DB::table('sources')->where('project_id', $projectId)->select('id')));
            }
        } else {
            [$col, $parent] = $how;
            $parentPks = self::rowsOfProject($parent, $projectId);
            $q = DB::table($tbl)->whereIn($col, $parentPks ?: ['']);
        }

        return $q->get($cols)->map(fn ($r) => self::pkString($tbl, (array) $r))->all();
    }

    /** @return array<int, array{0:string,1:string}> [child table, column] referring to $tbl */
    public static function children(string $tbl): array
    {
        $out = [];
        foreach (self::tables() as $child => $def) {
            foreach ($def['fk'] ?? [] as $col => $target) {
                if ($target === $tbl && $tbl !== 'projects') {
                    $out[] = [$child, $col];
                }
            }
        }

        return $out;
    }

    // ---- local <-> global ---------------------------------------------------

    /**
     * A local row's replicated fields with references as global ids.
     *
     * @return array{0: array, 1: array<string, int>} data, user gid => local user id
     */
    public static function toGlobal(string $tbl, array $row): array
    {
        $def = self::def($tbl);
        $data = [];
        $users = [];
        foreach (self::fields($tbl) as $col) {
            $value = $row[$col] ?? null;
            if ($value !== null && $value !== '' && isset($def['fk'][$col])) {
                $target = $def['fk'][$col];
                $value = IdMap::ensureGid($target, (string) $value);
                if ($target === 'users') {
                    $users[$value] = (int) $row[$col];
                }
            } elseif ($value !== null && $value !== '' && isset($def['poly'][$col])) {
                [$typeCol, $map] = $def['poly'][$col];
                $target = $map[$row[$typeCol] ?? ''] ?? null;
                if ($target && self::has($target)) {
                    $value = IdMap::ensureGid($target, (string) $value);
                }
            }
            $data[$col] = $value;
        }
        foreach (self::pkCols($tbl) as $col) {
            if (self::composite($tbl) && isset($def['fk'][$col])) {
                $data[$col] = IdMap::ensureGid($def['fk'][$col], (string) $row[$col]);
            }
        }

        return [$data, $users];
    }

    /**
     * Local column values for merged global data.
     *
     * @return array{cols: array, missing: ?array, deleted: ?array} missing/deleted
     *                                                              name the parent [table, gid] that is not here yet / was deleted
     */
    public static function toLocal(string $tbl, array $data, string $projectGid): array
    {
        $def = self::def($tbl);
        $cols = [];
        $fields = self::fields($tbl);
        if (self::composite($tbl)) {
            $fields = array_merge(self::pkCols($tbl), $fields);
        }
        foreach ($fields as $col) {
            if (! array_key_exists($col, $data)) {
                continue;
            }
            $value = $data[$col];
            if ($value !== null && $value !== '' && isset($def['fk'][$col])) {
                $target = $def['fk'][$col];
                if ($target === 'users') {
                    $value = Users::localFor((string) $value, $projectGid);
                } else {
                    $local = IdMap::local($target, (string) $value);
                    if ($local === null) {
                        $parent = Shadow::get($target, (string) $value);
                        $ref = [$target, (string) $value];

                        return ['cols' => [], 'missing' => $parent && $parent->deleted ? null : $ref,
                            'deleted' => $parent && $parent->deleted ? $ref : null];
                    }
                    $parent = Shadow::get($target, (string) $value);
                    if ($parent && $parent->deleted) {
                        return ['cols' => [], 'missing' => null, 'deleted' => [$target, (string) $value]];
                    }
                    $value = $local;
                }
            } elseif ($value !== null && $value !== '' && isset($def['poly'][$col])) {
                [$typeCol, $map] = $def['poly'][$col];
                $target = $map[$data[$typeCol] ?? ''] ?? null;
                if ($target && self::has($target)) {
                    $value = IdMap::local($target, (string) $value) ?? $value;
                }
            }
            $cols[$col] = is_array($value) ? json_encode($value) : $value;
        }

        return ['cols' => $cols, 'missing' => null, 'deleted' => null];
    }

    /** The gid of a table with natural key columns, from their global values. */
    public static function naturalGid(string $tbl, array $row): ?string
    {
        $def = self::def($tbl);
        if (empty($def['natural'])) {
            return null;
        }
        $parts = [$tbl];
        foreach ($def['natural'] as $col) {
            $value = $row[$col] ?? null;
            if ($value === null) {
                return null;
            }
            if (isset($def['fk'][$col])) {
                $value = IdMap::ensureGid($def['fk'][$col], (string) $value);
            } elseif (isset($def['poly'][$col])) {
                [$typeCol, $map] = $def['poly'][$col];
                $target = $map[$row[$typeCol] ?? ''] ?? null;
                if ($target && self::has($target)) {
                    $value = IdMap::ensureGid($target, (string) $value);
                }
            }
            $parts[] = (string) $value;
        }

        return Uuid::uuid5(self::NAMESPACE, implode('|', $parts))->toString();
    }

    /** @return array<int, string[]> */
    public static function uniques(string $tbl): array
    {
        return self::def($tbl)['unique'] ?? [];
    }
}
