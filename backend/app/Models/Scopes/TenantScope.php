<?php
namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Constrains tenant-owned models to the active organization, when one is set. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $orgId = TenantContext::id();
        if ($orgId !== null) {
            $builder->where($model->getTable() . '.organization_id', $orgId);
        }
    }
}
