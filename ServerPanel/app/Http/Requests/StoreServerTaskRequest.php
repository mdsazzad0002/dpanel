<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'server_id' => ['required', 'exists:servers,id'],
            'title' => ['required', 'string', 'max:255'],
            'goal' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:critical,high,medium,low,info'],
        ];
    }
}
