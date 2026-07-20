<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSshMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'command' => ['required', 'string', 'max:12000'],
            'context' => ['nullable', 'string'],
            'success_output_sample' => ['nullable', 'string'],
            'error_signature' => ['nullable', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
        ];
    }
}
