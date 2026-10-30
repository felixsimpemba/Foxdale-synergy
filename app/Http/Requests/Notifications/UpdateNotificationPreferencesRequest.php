<?php

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
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
        return [
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['boolean'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['array'],
            'events.*.*' => [Rule::enum(NotificationChannel::class)],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.start' => ['sometimes', 'date_format:H:i'],
            'quiet_hours.end' => ['sometimes', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.*.*.enum' => 'Choose the app, browser push or email.',
        ];
    }

    /**
     * Only events the system knows about, so the client cannot store arbitrary keys.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('events'))) {
            $known = array_column(NotificationEvent::cases(), 'value');

            $this->merge([
                'events' => array_intersect_key($this->input('events'), array_flip($known)),
            ]);
        }
    }
}
