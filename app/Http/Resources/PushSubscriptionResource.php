<?php

namespace App\Http\Resources;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscribed browser. The endpoint and keys stay server-side; only the device name is
 * shown to the person.
 *
 * @mixin PushSubscription
 */
class PushSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device' => $this->deviceName(),
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
