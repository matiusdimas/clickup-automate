<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module' => ['nullable', 'string', 'max:100'],
            'aplikasi' => ['nullable', 'string', 'max:100'],
            'technician' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
