<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('excel_field')) {
            $this->merge(['excel_field' => trim((string) $this->input('excel_field'))]);
        }
        if ($this->has('excel_value')) {
            $this->merge(['excel_value' => trim((string) $this->input('excel_value'))]);
        }
        if ($this->has('target_module')) {
            $this->merge(['target_module' => strtoupper(trim((string) $this->input('target_module')))]);
        }
        if ($this->has('operator')) {
            $this->merge(['operator' => strtoupper(trim((string) $this->input('operator')))]);
        }

        $conditions = $this->input('conditions');
        if (is_array($conditions) && count($conditions) > 0) {
            $first = $conditions[0];
            if (blank($this->input('excel_field')) && filled(data_get($first, 'field'))) {
                $this->merge(['excel_field' => trim((string) data_get($first, 'field'))]);
            }
            if (blank($this->input('excel_value'))) {
                $this->merge(['excel_value' => trim((string) data_get($first, 'value', ''))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'excel_field' => ['required', 'string', 'max:100'],
            'excel_value' => ['nullable', 'string', 'max:255'],
            'target_module' => ['required', 'string', 'max:100'],
            'source_format' => ['required', 'string', 'in:ebesha,sdp'],
            'operator' => ['nullable', 'string', 'in:AND,OR'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required_with:conditions', 'string', 'max:100'],
            'conditions.*.operator' => ['nullable', 'string', 'in:equals,contains,not_equals,starts_with,ends_with,is_not_empty,is_empty'],
            'conditions.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
