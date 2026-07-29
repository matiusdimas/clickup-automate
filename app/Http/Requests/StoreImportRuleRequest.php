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
            $this->merge(['excel_field' => trim($this->input('excel_field'))]);
        }
        if ($this->has('excel_value')) {
            $this->merge(['excel_value' => trim($this->input('excel_value'))]);
        }
        if ($this->has('target_module')) {
            $this->merge(['target_module' => strtoupper(trim($this->input('target_module')))]);
        }
    }

    public function rules(): array
    {
        return [
            'excel_field' => ['required', 'string', 'max:100'],
            'excel_value' => ['required', 'string', 'max:255'],
            'target_module' => ['required', 'string', 'max:100'],
            'source_format' => ['required', 'string', 'in:ebesha,sdp'],
        ];
    }
}
