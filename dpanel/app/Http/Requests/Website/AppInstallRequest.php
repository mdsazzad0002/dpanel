<?php

namespace App\Http\Requests\Website;

use App\Services\Website\Apps\AppInstallerRegistry;
use Illuminate\Foundation\Http\FormRequest;

class AppInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared rules plus the app's own fields from its installer class.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            // Up to four parts: OpenCart versions look like 4.1.0.3.
            'version' => ['required', 'string', 'regex:/^(\d+(\.\d+){0,3}|upload)$/'],
            // "new", "none" (CodeIgniter only), or the id of one of the website's databases.
            'database_id' => ['required', 'string', 'max:64'],
            'database_suffix' => ['nullable', 'string', 'regex:/^[a-z0-9]{4}$/'],
            // Engine for a new database; an existing one keeps its own.
            'database_engine' => ['nullable', 'string', 'in:mariadb,postgresql'],
        ];

        $registry = app(AppInstallerRegistry::class);
        $app = (string) $this->route('app');

        return $registry->has($app) ? $rules + $registry->get($app)->rules() : $rules;
    }
}
