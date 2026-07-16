<?php

namespace App\Http\Requests\Website;

use Illuminate\Foundation\Http\FormRequest;

class WordpressInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'wordpress_version' => ['nullable', 'string', 'max:20', 'regex:/^(latest|\\d+\\.\\d+(?:\\.\\d+)?)$/i'],
            'database_prefix' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'return_to' => ['nullable', 'string', 'in:manage,wordpress'],
        ];
    }
}
