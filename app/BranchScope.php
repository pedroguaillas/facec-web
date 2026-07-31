<?php

namespace App;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BranchScope
{
    protected static function bootBranchScope()
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            // Si necesitas el id del usuario logueado:
            $companyId = Auth::user()->company?->id ?? null;

            // Puedes pasar otro origen de $companyId si lo prefieres
            if ($companyId) {
                $branch = Branch::where('company_id', $companyId)
                    ->orderBy('created_at')
                    ->first();
                if ($branch) {
                    $builder->where(
                        $builder->getModel()->getTable().'.branch_id',
                        $branch->id,
                    );
                }
            }
        });
    }
}
