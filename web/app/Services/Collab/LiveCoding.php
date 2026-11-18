<?php

namespace App\Services\Collab;

use App\Events\Coding\CodeChanged;
use App\Events\Coding\SelectionChanged;
use App\Models\Code;
use App\Models\Selection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells other team members on the coding page about changes, so that coded
 * segments and codes update live. Broadcasting is best effort: if the
 * WebSocket server (Reverb) is down or not configured, the change is still
 * saved and the others see it on their next page load.
 */
class LiveCoding
{
    public static function selection(Selection $selection, string $action): void
    {
        self::send(new SelectionChanged(
            (string) $selection->source_id,
            $action,
            [
                'id' => (string) $selection->id,
                'code_id' => (string) $selection->code_id,
                'start' => (int) $selection->start_position,
                'end' => (int) $selection->end_position,
                'creating_user_id' => $selection->creating_user_id !== null ? (int) $selection->creating_user_id : null,
                'origin' => $selection->origin,
            ],
            Auth::id(),
        ));
    }

    /**
     * Many segments of a source changed at once, e.g. by bulk autocoding.
     */
    public static function sourceStale(string $sourceId, int $count): void
    {
        self::send(new SelectionChanged($sourceId, SelectionChanged::STALE, ['count' => $count], Auth::id()));
    }

    public static function code(Code $code, string $action, int $projectId): void
    {
        $payload = ['id' => (string) $code->id];
        if ($action !== CodeChanged::DELETED) {
            $payload += [
                'name' => $code->name,
                'color' => $code->color,
                'description' => $code->description ?? '',
                'codebook' => (int) $code->codebook_id,
                'parent_id' => $code->parent_id !== null ? (string) $code->parent_id : null,
                'kind' => $code->kind ?? 'code',
            ];
        }

        self::send(new CodeChanged($projectId, $action, $payload, Auth::id()));
    }

    protected static function send(SelectionChanged|CodeChanged $event): void
    {
        try {
            // the sender already applied the change locally
            $event->dontBroadcastToCurrentUser();
            event($event);
        } catch (Throwable $e) {
            Log::warning('Live coding update could not be broadcast.', [
                'event' => $event::class,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
