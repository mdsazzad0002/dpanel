<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'numeric', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'auth_type' => ['required', 'in:password,key'],
            'password' => ['required_if:auth_type,password', 'nullable', 'string'],
            'private_key' => ['required_if:auth_type,key', 'nullable', 'string'],
            'private_key_passphrase' => ['nullable', 'string'],
            'mode' => ['nullable', 'in:setup,normal,emergency'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
