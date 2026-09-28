<?php

namespace App\Http\Requests\Website;

use App\Services\Website\LaravelInstallService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaravelInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stack' => ['required', 'string', Rule::in(array_keys(LaravelInstallService::STACKS))],
            'laravel_version' => ['required', 'string', Rule::in(array_keys(LaravelInstallService::VERSIONS))],
            // "new", or the id of an existing database (visibility is checked in the controller and job).
            'database_id' => ['required', 'string', 'max:64'],
            'database_suffix' => ['nullable', 'string', 'regex:/^[a-z0-9]{4}$/'],
            'push_to_git' => ['nullable', 'boolean'],
        ];
    }
}
