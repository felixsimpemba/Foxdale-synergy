<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A browser's PushSubscription.toJSON(): the endpoint and the two keys it generated.
 */
class StorePushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'url:https', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.required' => 'This browser did not return a push endpoint.',
            'endpoint.url' => 'Push endpoints must use HTTPS.',
            'keys.p256dh.required' => 'This browser did not return its public key.',
            'keys.auth.required' => 'This browser did not return its auth secret.',
        ];
    }
}
