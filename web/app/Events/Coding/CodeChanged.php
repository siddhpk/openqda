<?php

namespace App\Events\Coding;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A code of a project was created, changed (name, colour, description,
 * type or parent) or deleted. Sent to the other team members on the coding
 * page of that project.
 */
class CodeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * @param  array{id: string, name?: string, color?: ?string, description?: ?string, codebook?: int, parent_id?: ?string, kind?: string}  $code
     */
    public function __construct(
        public int $projectId,
        public string $action,
        public array $code,
        public ?int $userId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('coding-project.'.$this->projectId);
    }

    public function broadcastAs(): string
    {
        return 'code.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'code' => $this->code,
            'userId' => $this->userId,
        ];
    }
}
