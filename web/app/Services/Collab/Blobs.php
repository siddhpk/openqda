<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Source files in collaborative projects. A source replicates the SHA-256
 * of its original file (_file, with its extension _ext) and of its
 * converted HTML (_html) instead of local paths. The node agent copies
 * missing files from any replica (checked against the hash); they are
 * stored at the usual local path (projects/{local project}/sources/...),
 * so the coding view and ValidatesStoragePath work unchanged, and the HTML
 * every laptop shows is identical (codings refer to its characters).
 */
final class Blobs
{
    public static function dir(int $projectId): string
    {
        return storage_path("app/projects/{$projectId}/sources");
    }

    public static function htmlPath(string $sourceId): ?string
    {
        return DB::table('source_statuses')->where('source_id', $sourceId)->where('status', 'like', 'converted:%')
            ->orderByDesc('updated_at')->value('path');
    }

    /** File fields of a source row (for change capture). */
    public static function fields(array $row): array
    {
        $out = [];
        $upload = $row['upload_path'] ?? null;
        if ($upload && is_file($upload)) {
            $out['_file'] = self::hash((string) $row['id'], 'file', $upload);
            $out['_ext'] = strtolower(pathinfo($upload, PATHINFO_EXTENSION));
        }
        $html = self::htmlPath((string) $row['id']);
        if ($html && is_file($html)) {
            $out['_html'] = self::hash((string) $row['id'], 'html', $html);
        }

        return $out;
    }

    /** SHA-256 of a file, recomputed only when its size or time changed. */
    public static function hash(string $sourceId, string $kind, string $path): string
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $known = DB::table('collab_files')->where('source_id', $sourceId)->where('kind', $kind)->first();
        if ($known && (int) $known->size === $size && (int) $known->mtime === $mtime && $known->sha) {
            return $known->sha;
        }
        $sha = hash_file('sha256', $path);
        DB::table('collab_files')->updateOrInsert(['source_id' => $sourceId, 'kind' => $kind], ['sha' => $sha, 'size' => $size, 'mtime' => $mtime]);

        return $sha;
    }

    /** Mark sources whose files changed on disk (an edited text) for capture. */
    public static function scan(): void
    {
        $projects = DB::table('collab_projects')->where('status', 'active')->whereNotNull('project_id')->pluck('project_id');
        if ($projects->isEmpty()) {
            return;
        }
        foreach (DB::table('sources')->whereIn('project_id', $projects)->get(['id', 'upload_path']) as $s) {
            foreach (['file' => $s->upload_path, 'html' => self::htmlPath($s->id)] as $kind => $path) {
                if (! $path || ! is_file($path)) {
                    continue;
                }
                clearstatcache(true, $path);
                $known = DB::table('collab_files')->where('source_id', $s->id)->where('kind', $kind)->first();
                if (! $known || (int) $known->size !== (int) filesize($path) || (int) $known->mtime !== (int) filemtime($path)) {
                    DB::table('collab_dirty')->insertOrIgnore(['tbl' => 'sources', 'pk' => $s->id]);
                }
            }
        }
    }

    /** After a remote source was written: local paths, and the files already here. */
    public static function afterSource(string $localId, array $values, string $projectGid): void
    {
        $projectId = Collab::projectId($projectGid);
        if (! $projectId) {
            return;
        }
        $dir = self::dir($projectId);
        if (! empty($values['_ext'])) {
            $path = "{$dir}/{$localId}.".preg_replace('/[^a-z0-9]/', '', strtolower($values['_ext']));
            DB::table('sources')->where('id', $localId)->update(['upload_path' => $path]);
        }
        if (! empty($values['_html'])) {
            $path = "{$dir}/{$localId}.html";
            if (is_file($path) && hash_file('sha256', $path) === $values['_html']) {
                self::setConverted($localId, $path);
            }
        }
    }

    private static function setConverted(string $sourceId, string $path): void
    {
        $status = DB::table('source_statuses')->where('source_id', $sourceId)->first();
        if ($status) {
            DB::table('source_statuses')->where('id', $status->id)->update(['status' => 'converted:html', 'path' => $path, 'updated_at' => now()]);
        } else {
            DB::table('source_statuses')->insert(['id' => (string) Str::uuid(), 'source_id' => $sourceId, 'status' => 'converted:html',
                'path' => $path, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Files this laptop still needs.
     *
     * @return array<int, array{sha:string, project:string}>
     */
    public static function missing(?string $projectGid = null): array
    {
        $q = DB::table('collab_rows')->where('tbl', 'sources')->where('deleted', false)->where('state', 'ok');
        if ($projectGid) {
            $q->where('project_gid', $projectGid);
        }
        $out = [];
        foreach ($q->get(['gid', 'project_gid', 'data']) as $row) {
            $data = json_decode($row->data ?? '[]', true) ?: [];
            $local = IdMap::local('sources', $row->gid);
            if (! $local) {
                continue;
            }
            foreach (['_file' => 'file', '_html' => 'html'] as $field => $kind) {
                $sha = $data[$field] ?? null;
                if (! $sha) {
                    continue;
                }
                $known = DB::table('collab_files')->where('source_id', $local)->where('kind', $kind)->value('sha');
                $path = $kind === 'file' ? DB::table('sources')->where('id', $local)->value('upload_path') : self::htmlPath($local);
                if ($known !== $sha || ! $path || ! is_file($path)) {
                    $out[$sha] = ['sha' => $sha, 'project' => $row->project_gid];
                }
            }
        }

        return array_values($out);
    }

    /** Store a received file wherever a source needs it. Returns how many places got it. */
    public static function place(string $sha, string $tmpPath): int
    {
        $placed = 0;
        $rows = DB::table('collab_rows')->where('tbl', 'sources')->where('data', 'like', '%'.$sha.'%')->get(['gid', 'project_gid', 'data']);
        Engine::applying(function () use ($rows, $sha, $tmpPath, &$placed) {
            foreach ($rows as $row) {
                $data = json_decode($row->data ?? '[]', true) ?: [];
                $local = IdMap::local('sources', $row->gid);
                $projectId = Collab::projectId($row->project_gid);
                if (! $local || ! $projectId) {
                    continue;
                }
                $dir = self::dir($projectId);
                if (! is_dir($dir)) {
                    mkdir($dir, 0o755, true);
                }
                if (($data['_file'] ?? null) === $sha) {
                    $ext = preg_replace('/[^a-z0-9]/', '', strtolower($data['_ext'] ?? 'bin')) ?: 'bin';
                    $path = "{$dir}/{$local}.{$ext}";
                    copy($tmpPath, $path);
                    DB::table('sources')->where('id', $local)->update(['upload_path' => $path]);
                    self::hash($local, 'file', $path);
                    $placed++;
                }
                if (($data['_html'] ?? null) === $sha) {
                    $path = "{$dir}/{$local}.html";
                    copy($tmpPath, $path);
                    self::setConverted($local, $path);
                    self::hash($local, 'html', $path);
                    $placed++;
                }
            }
        });

        return $placed;
    }

    /** The local path of a file a member node may download, or null. */
    public static function locate(string $sha, string $node): ?string
    {
        foreach (DB::table('collab_files')->where('sha', $sha)->get(['source_id', 'kind']) as $f) {
            $gid = IdMap::gid('sources', $f->source_id);
            $shadow = $gid ? Shadow::get('sources', $gid) : null;
            if (! $shadow || $shadow->deleted || Roster::role($shadow->project_gid, $node) === null) {
                continue;
            }
            $path = $f->kind === 'file' ? DB::table('sources')->where('id', $f->source_id)->value('upload_path') : self::htmlPath($f->source_id);
            if ($path && is_file($path) && hash_file('sha256', $path) === $sha) {
                return $path;
            }
        }

        return null;
    }
}
