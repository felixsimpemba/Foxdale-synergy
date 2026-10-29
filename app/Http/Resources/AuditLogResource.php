<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'api_key_id' => $this->api_key_id,
            'token_id' => $this->token_id,
            'source' => $this->source,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'changes' => $this->changes,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
