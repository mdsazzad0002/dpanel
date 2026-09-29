<?php

namespace App\Http\Requests\Website;

use Illuminate\Foundation\Http\FormRequest;

class AppInstallRequest extends FormRequest
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
        $rules = [
            'version' => ['required', 'string', 'regex:/^(\d+(\.\d+){0,2}|upload)$/'],
            // "new", "none" (CodeIgniter only), or the id of one of the website's databases.
            'database_id' => ['required', 'string', 'max:64'],
            'database_suffix' => ['nullable', 'string', 'regex:/^[a-z0-9]{4}$/'],
        ];

        if ($this->route('app') === 'joomla') {
            $rules += [
                'site_name' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
                'admin_name' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
                'admin_username' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9_.@-]+$/'],
                'admin_email' => ['required', 'email', 'max:190'],
                'admin_password' => ['required', 'string', 'min:12', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            ];
        }

        if ($this->route('app') === 'whmcs') {
            $rules += [
                'upload_id' => ['required', 'uuid'],
                'license_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
                'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
                'admin_password' => ['required', 'string', 'min:12', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            ];
        }

        return $rules;
    }
}
