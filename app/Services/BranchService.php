<?php

namespace App\Services;

use App\Models\Branch;

class BranchService
{
    public function createFinalConsumer(Branch $branch)
    {
        $branch->customers()->create([
            'type_identification' => 'cf',
            'identication' => '9999999999999',
            'name' => 'Consumidor Final',
        ]);
    }

    public function create(array $attributes)
    {
        return Branch::create($attributes);
    }

    public function getByAttributes(array $attributes, array $with = [], array $columns = ['*'])
    {
        return Branch::select($columns)
            ->where($attributes)
            ->with($with)
            ->get();
    }

    public function findByAttributes(array $attributes, array $with = [], array $columns = ['*'])
    {
        return Branch::select($columns)
            ->where($attributes)
            ->with($with)
            ->firstOrFail();
    }

    public function getOnlyIfIsUnique(array $attributes, array $with = [], array $columns = ['*'])
    {
        $branches = Branch::select($columns)
            ->where($attributes)
            ->with($with)
            ->get();

        return ($branches->count() === 1) ? $branches->first() : null;
    }
}
