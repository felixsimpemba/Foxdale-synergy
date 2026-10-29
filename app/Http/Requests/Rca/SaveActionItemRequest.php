<?php

namespace App\Http\Requests\Rca;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\ActionItemType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add (POST) or edit (PUT) an action item. Owner and due date may be left empty while
 * drafting, but every item needs both before the RCA can be approved. "Won't do" needs a
 * reason in the note.
 */
class SaveActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$required, Rule::enum(ActionItemType::class)],
            'title' => [$required, 'string', 'max:300'],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'priority' => ['sometimes', Rule::enum(ActionItemPriority::class)],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::enum(ActionItemStatus::class)],
            'note' => [
                Rule::requiredIf($this->input('status') === ActionItemStatus::WontDo->value),
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_id.exists' => 'Choose an active user as the owner.',
            'note.required' => 'Give a reason for not doing this item.',
            'status.prohibited' => 'New action items start open.',
        ];
    }
}
