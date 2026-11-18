<?php

namespace App\Events\Coding;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A coded segment of a source was created, recoded or deleted. Sent to the
 * other team members who have that source open on the coding page.
 *
 * The segment's text is not sent: receivers read it from the source they
 * already have open, which keeps messages small.
 */
class SelectionChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /** many segments changed at once (bulk autocoding); receivers offer a reload */
    public const STALE = 'stale';

    /**
     * @param  array{id?: string, code_id?: string, start?: int, end?: int, creating_user_id?: int, origin?: ?string, count?: int}  $selection
     */
    public function __construct(
        public string $sourceId,
        public string $action,
        public array $selection,
        public ?int $userId,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('coding.'.$this->sourceId);
    }

    public function broadcastAs(): string
    {
        return 'selection.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'sourceId' => $this->sourceId,
            'selection' => $this->selection,
            'userId' => $this->userId,
        ];
    }
}
