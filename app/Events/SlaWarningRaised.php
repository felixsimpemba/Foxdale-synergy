<?php

namespace App\Events;

use App\Enums\SlaMetric;
use App\Models\Incident;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once when 80% of an SLA target has elapsed without the target being met.
 */
class SlaWarningRaised implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public SlaMetric $metric,
    ) {}
}
