<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentType;
use App\Services\SettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Multipart upload. Size and allowed types come from the runtime settings
 * (uploads.max_kb, uploads.allowed_extensions); both the extension and the detected
 * content type must be allowed.
 */
class StoreDocumentRequest extends FormRequest
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
            'file' => self::fileRules(),
            'type' => ['required', Rule::enum(DocumentType::class)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function fileRules(): array
    {
        $settings = app(SettingsService::class);
        $extensions = $settings->get('uploads.allowed_extensions');

        return [
            'required',
            'file',
            'extensions:'.implode(',', $extensions),
            File::types($extensions)->max($settings->get('uploads.max_kb')),
        ];
    }
}
