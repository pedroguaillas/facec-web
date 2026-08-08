<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

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

    public function create(array $attributes): Branch
    {
        return DB::transaction(function () use ($attributes) {
            $branch = Branch::create($attributes);

            if ($attributes['type'] === 'matriz') {
                $this->demoteOtherMatrices($branch);
            }

            return $branch;
        });
    }

    public function update(Branch $branch, array $attributes): Branch
    {
        return DB::transaction(function () use ($branch, $attributes) {
            $branch->update($attributes);

            if (($attributes['type'] ?? null) === 'matriz') {
                $this->demoteOtherMatrices($branch);
            }

            return $branch;
        });
    }

    private function demoteOtherMatrices(Branch $branch): void
    {
        Branch::where('company_id', $branch->company_id)
            ->where('type', 'matriz')
            ->where('id', '<>', $branch->id)
            ->update(['type' => 'sucursal']);
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
