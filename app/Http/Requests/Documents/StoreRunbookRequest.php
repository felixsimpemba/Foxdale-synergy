<?php

namespace App\Http\Requests\Documents;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for create (POST) and update (PUT); on update every field is optional.
 */
class StoreRunbookRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:100000'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }
}
