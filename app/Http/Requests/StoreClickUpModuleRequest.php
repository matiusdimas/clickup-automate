<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClickUpModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'module_name' => strtoupper(trim((string) $this->input('module_name'))),
            'clickup_view_id' => trim((string) $this->input('clickup_view_id')),
            'clickup_list_id' => trim((string) $this->input('clickup_list_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'module_name' => ['required', 'string', 'max:100', 'unique:clickup_modules,module_name'],
            'clickup_view_id' => ['required', 'string', 'max:120'],
            'clickup_list_id' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
