<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides extension service accounts (backlog U7) from every User query. The
 * session guard and password reset look users up through Eloquent too, so a
 * service account can never sign in, and it never shows up in user lists,
 * counts or "notify every admin". Only an extension's own API token reaches
 * it, through relations that drop this scope by name.
 */
class HumanUserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_service_account'), false);
    }
}
