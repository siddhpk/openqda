<?php

namespace App\Services\Collab;

use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Live co-editing of texts (memo content, the text of an unlocked source).
 *
 * The browsers edit a shared Yjs document. Every update travels twice:
 * at once through the node agent to the other open editors (fast path) and
 * as a signed "yupd" operation (durable path), so editors that were offline
 * merge character by character when they come back. The text itself is
 * still saved the usual way (memo update, source autosave) by one editor
 * (the save leader); that save carries the document generation (_ygen) and
 * the hash it produced (_yhash). A text changed some other way starts a new
 * generation from its current content.
 *
 * Document keys: "notes:<id>:content" or "sources:<id>:html" in the browser,
 * the same with the global id between the agents.
 */
final class Docs
{
    /** @return array{0:string,1:string,2:string}|null */
    public static function parse(string $key): ?array
    {
        $parts = explode(':', $key, 3);
        if (count($parts) !== 3 || ! isset(SyncSchema::DOCS[$parts[0]][$parts[2]]) || $parts[1] === '') {
            return null;
        }

        return $parts;
    }

    public static function contentHash(string $tbl, string $localId): ?string
    {
        if ($tbl === 'notes') {
            $content = DB::table('notes')->where('id', $localId)->value('content');

            return $content === null ? null : hash('sha256', (string) $content);
        }
        if ($tbl === 'sources') {
            $path = Blobs::htmlPath($localId);

            return $path && is_file($path) ? hash_file('sha256', $path) : null;
        }

        return null;
    }

    /** The project and global key of a browser document key (flushes pending changes first). */
    public static function resolve(string $localKey): ?array
    {
        [$tbl, $local, $field] = self::parse($localKey) ?? [null, null, null];
        if (! $tbl || ! SyncSchema::has($tbl)) {
            return null;
        }
        $row = SyncSchema::load($tbl, $local);
        if (! $row) {
            return null;
        }
        $projectId = SyncSchema::projectIdOf($tbl, $row);

        return ['tbl' => $tbl, 'local' => $local, 'field' => $field, 'row' => $row, 'project_id' => $projectId,
            'project_gid' => Collab::projectGid($projectId)];
    }

    /**
     * Everything an editor needs to join the shared document.
     */
    public static function open(int $userId, array $r, bool $userMayEdit): array
    {
        if (! $r['project_gid'] || ! SyncSchema::replicable($r['tbl'], $r['row'])) {
            return ['collaborative' => false];
        }
        if ($batches = ChangeCapture::flush()) {
            ControlClient::publish($batches);
        }
        $gid = IdMap::ensureGid($r['tbl'], $r['local']);
        $shadow = Shadow::get($r['tbl'], $gid);
        $hash = self::contentHash($r['tbl'], $r['local']) ?? '';
        $data = $shadow?->data ?? [];
        $gen = ! empty($data['_ygen']) && ($data['_yhash'] ?? null) === $hash ? (string) $data['_ygen'] : $hash;

        $updates = [];
        $lastUpdate = '';
        foreach (self::updates($r['tbl'], $gid, $r['field'], $gen) as $op) {
            array_push($updates, ...(array) $op['f']['u']);
            $lastUpdate = $op['h'];
        }
        $role = Roster::myRole($r['project_gid']);
        $canEdit = $userMayEdit && ReviewPolicy::mayCoEdit($role, Collab::node(), $shadow?->creator);
        $doc = "{$r['tbl']}:{$gid}:{$r['field']}";
        // when the text was last saved (sources save their converted HTML)
        $column = SyncSchema::DOCS[$r['tbl']][$r['field']] ?? '_html';
        $heads = $shadow?->heads[$column] ?? [];
        $savedAt = $heads ? max(array_column($heads, 2)) : '';

        return [
            'collaborative' => true,
            'doc' => $doc,
            'gen' => $gen,
            'updates' => $updates,
            'can_edit' => $canEdit,
            'role' => $role,
            'token' => LiveTokens::doc($userId, $r['project_gid'], $doc, $canEdit),
            // offline edits were merged after the last save: the opener saves the merged text
            'needs_finalize' => $lastUpdate !== '' && strcmp($lastUpdate, (string) $savedAt) > 0,
        ];
    }

    /** @return array<int, array> effective update ops of one generation (oldest first) */
    public static function updates(string $tbl, string $gid, string $field, string $gen): array
    {
        $self = Collab::node();

        return array_values(array_filter(OpLog::forRow($tbl, $gid, ['yupd']), fn ($op) => ($op['f']['field'] ?? null) === $field
            && ($op['f']['gen'] ?? null) === $gen && ($op['_status'] === 'applied' || $op['o'] === $self)));
    }

    /** Store updates typed here as a signed op and send it on. */
    public static function append(string $doc, string $gen, array $updates): ?array
    {
        [$tbl, $gid, $field] = self::parse($doc) ?? [null, null, null];
        $shadow = $tbl ? Shadow::get($tbl, $gid) : null;
        if (! $shadow || ! Collab::projectId($shadow->project_gid)) {
            return null;
        }
        $role = Roster::myRole($shadow->project_gid);
        if (! ReviewPolicy::mayCoEdit($role, Collab::node(), $shadow->creator)) {
            return null;
        }
        $op = DB::transaction(fn () => OpLog::append($shadow->project_gid, $tbl, $gid, 'yupd', ['field' => $field, 'gen' => $gen, 'u' => array_values($updates)]));
        ControlClient::publish([['project' => $shadow->project_gid, 'ops' => [$op], 'events' => []]]);

        return $op;
    }

    /** Which nodes may live-edit a document (asked by the node agent). */
    public static function info(string $doc): ?array
    {
        [$tbl, $gid] = self::parse($doc) ?? [null, null];
        $shadow = $tbl ? Shadow::get($tbl, $gid) : null;
        if (! $shadow) {
            return null;
        }
        $editors = Roster::primaries($shadow->project_gid);
        if ($shadow->creator && Roster::role($shadow->project_gid, $shadow->creator) !== null) {
            $editors[] = $shadow->creator;
        }

        return ['project' => $shadow->project_gid, 'editors' => array_values(array_unique($editors))];
    }
}
