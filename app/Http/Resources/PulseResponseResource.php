<?php

namespace App\Http\Resources;

use App\Models\PulseResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PulseResponse
 */
class PulseResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dept' => $this->department,
            'department_name' => $this->departmentName(),
            'name' => $this->name,
            'role' => $this->role,
            'answers' => $this->answers ?? [],
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
