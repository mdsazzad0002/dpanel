<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommandJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'server_id' => ['required', 'exists:servers,id'],
            'command' => ['required', 'string', 'max:12000'],
            'task_id' => ['nullable', 'exists:server_tasks,id'],
            'parent_id' => ['nullable', 'exists:command_jobs,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
        ];
    }
}
