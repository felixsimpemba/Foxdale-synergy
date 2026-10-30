<?php

namespace App\Events;

use App\Models\IncidentUpdate;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an update is posted on the timeline. $notifyChannels asks for chat delivery.
 */
class UpdatePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public IncidentUpdate $update,
        public bool $notifyChannels,
    ) {}
}
