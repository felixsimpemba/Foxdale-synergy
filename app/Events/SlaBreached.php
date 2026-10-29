<?php

namespace App\Events;

use App\Enums\SlaMetric;
use App\Models\Incident;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once when an SLA target is missed.
 */
class SlaBreached implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public SlaMetric $metric,
    ) {}
}
