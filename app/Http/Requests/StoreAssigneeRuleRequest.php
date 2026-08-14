<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssigneeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule_name' => ['nullable', 'string', 'max:255'],
            'app_category' => ['required', 'string', 'in:MAIN,INFRA,ALL,SPECIFIC'],
            'target_app' => ['nullable', 'string', 'max:255'],
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['required', 'integer'],
            'assignee_names' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
        ];
    }
}
