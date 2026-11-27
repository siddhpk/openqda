<?php

namespace App\Services\Collab;

/**
 * Change notifications for the browsers (through the node agent's live
 * channel). They carry local ids, and for the rows the coding view updates
 * in place, the row itself.
 */
final class Events
{
    private const ROWS = [
        'selections' => ['id', 'code_id', 'source_id', 'start_position', 'end_position', 'text', 'description', 'creating_user_id', 'origin'],
        'codes' => ['id', 'name', 'color', 'codebook_id', 'parent_id', 'description', 'kind'],
        'codebooks' => ['id', 'name', 'project_id'],
        'notes' => ['id', 'content', 'type', 'target', 'visibility', 'memo_type', 'creating_user_id'],
        'sources' => ['id', 'name', 'type'],
        'projects' => ['id', 'name', 'description'],
    ];

    public static function upsert(string $tbl, string $local, string $projectGid, ?array $row = null): array
    {
        $event = ['tbl' => $tbl, 'id' => $local, 'kind' => 'upsert', 'project' => Collab::projectId($projectGid)];
        if (isset(self::ROWS[$tbl])) {
            $row ??= SyncSchema::load($tbl, $local);
            if ($row) {
                $event['row'] = array_intersect_key($row, array_flip(self::ROWS[$tbl]));
            }
        }

        return $event;
    }

    public static function deleted(string $tbl, string $local, string $projectGid): array
    {
        return ['tbl' => $tbl, 'id' => $local, 'kind' => 'delete', 'project' => Collab::projectId($projectGid)];
    }
}
