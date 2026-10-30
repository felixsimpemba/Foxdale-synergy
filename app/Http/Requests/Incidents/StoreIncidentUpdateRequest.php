<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentUpdateType;
use App\Enums\UpdateVisibility;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Models\Incident;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * JSON, or multipart when files are attached. A post needs text, files, or both. parent_id
 * makes it a reply to another comment on the same incident.
 */
class StoreIncidentUpdateRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 10;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');

        return [
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:10000'],
            'type' => ['nullable', Rule::in([IncidentUpdateType::Comment->value, IncidentUpdateType::StatusUpdate->value])],
            'visibility' => ['nullable', Rule::enum(UpdateVisibility::class)],
            'notify_channels' => ['sometimes', 'boolean'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('incident_updates', 'id')
                    ->where('incident_id', $incident->id)
                    ->whereNull('deleted_at')
                    ->whereIn('type', [IncidentUpdateType::Comment->value, IncidentUpdateType::StatusUpdate->value]),
            ],
            'attachments' => ['sometimes', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => array_values(array_filter(StoreDocumentRequest::fileRules(), fn (mixed $rule): bool => $rule !== 'required')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a message or attach a file.',
            'parent_id.exists' => 'That comment no longer exists, so you cannot reply to it.',
            'attachments.max' => 'Attach at most '.self::MAX_ATTACHMENTS.' files at a time.',
        ];
    }

    /**
     * Report file errors on "attachments" with the file name, rather than "attachments.3".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];

        foreach ($this->file('attachments', []) as $index => $file) {
            $names["attachments.{$index}"] = '"'.$file->getClientOriginalName().'"';
        }

        return $names;
    }
}
