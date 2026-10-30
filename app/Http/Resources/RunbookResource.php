<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Runbook;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * body is the Markdown source; body_html is rendered with raw HTML stripped and unsafe links
 * (javascript:, data:) removed, so it is safe to display.
 *
 * @mixin Runbook
 */
class RunbookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'body_html' => Runbook::renderMarkdown($this->body),
            'service_id' => $this->service_id,
            'service' => $this->whenLoaded('service', fn () => $this->summary($this->service)),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->summary($this->category)),
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => $this->summary($this->creator)),
            'updated_by' => $this->updated_by,
            'editor' => $this->whenLoaded('editor', fn () => $this->summary($this->editor)),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function summary(Service|Category|User|null $model): ?array
    {
        return $model === null ? null : ['id' => $model->id, 'name' => $model->name];
    }
}
