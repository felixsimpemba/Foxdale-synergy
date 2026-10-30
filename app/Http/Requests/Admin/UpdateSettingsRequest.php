<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body: { "settings": { "ai.enabled": true, "uploads.max_kb": 10240 } }. Every key must be a
 * known setting and every value must have the same type as the setting's default.
 */
class UpdateSettingsRequest extends FormRequest
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
            'settings' => ['required', 'array', 'min:1', $this->knownKeysWithMatchingTypes(...)],
        ];
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function knownKeysWithMatchingTypes(string $attribute, mixed $value, Closure $fail): void
    {
        $defaults = app(SettingsService::class)->defaults();

        foreach ((array) $value as $key => $setting) {
            if (! array_key_exists($key, $defaults)) {
                $fail("Unknown setting [{$key}].");

                continue;
            }

            if (gettype($setting) !== gettype($defaults[$key])) {
                $fail("The setting [{$key}] must be of type ".gettype($defaults[$key]).'.');

                continue;
            }

            if ($key === 'display_timezone' && ! in_array($setting, timezone_identifiers_list(), true)) {
                $fail('The setting [display_timezone] must be a valid timezone.');
            }
        }
    }
}
