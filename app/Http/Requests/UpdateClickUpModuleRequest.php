<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClickUpModuleRequest extends FormRequest
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
        $module = $this->route('module');

        return [
            'module_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('clickup_modules', 'module_name')->ignore($module ? $module->id : null),
            ],
            'clickup_view_id' => ['required', 'string', 'max:120'],
            'clickup_list_id' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
