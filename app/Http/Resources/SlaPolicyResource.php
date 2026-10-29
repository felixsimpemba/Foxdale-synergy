<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the targets returned by SlaService::targetsFor(), keyed by severity.
 */
class SlaPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'severity' => $this->resource['severity'],
            'ack_minutes' => $this->resource['ack'],
            'mitigate_minutes' => $this->resource['mitigate'],
            'resolve_minutes' => $this->resource['resolve'],
        ];
    }
}
