<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    public function create(array $attributes, ?UploadedFile $logo = null): Branch
    {
        return DB::transaction(function () use ($attributes, $logo) {
            $branch = Branch::create($attributes);

            if ($logo) {
                $this->storeLogo($branch, $logo);
            }

            if ($attributes['type'] === 'matriz') {
                $this->demoteOtherMatrices($branch);
            }

            return $branch;
        });
    }

    public function update(Branch $branch, array $attributes, ?UploadedFile $logo = null): Branch
    {
        return DB::transaction(function () use ($branch, $attributes, $logo) {
            $branch->update($attributes);

            if ($logo) {
                $this->storeLogo($branch, $logo);
            }

            if (($attributes['type'] ?? null) === 'matriz') {
                $this->demoteOtherMatrices($branch);
            }

            return $branch;
        });
    }

    /**
     * Quita el logo propio: el establecimiento vuelve a usar el de la empresa.
     */
    public function removeLogo(Branch $branch): Branch
    {
        if ($branch->logo_dir) {
            Storage::disk('public')->delete('logos/'.$branch->logo_dir);
        }

        $branch->update(['logo_dir' => null]);

        return $branch;
    }

    /**
     * Guarda el logo como `logos/{ruc}-{estab}.{ext}` (el de la empresa es
     * `{ruc}.{ext}`) y borra el anterior si tenía otra extensión.
     */
    private function storeLogo(Branch $branch, UploadedFile $logo): void
    {
        $filename = $branch->company->ruc.'-'.str_pad((string) $branch->store, 3, '0', STR_PAD_LEFT).'.'.$logo->getClientOriginalExtension();

        if ($branch->logo_dir && $branch->logo_dir !== $filename) {
            Storage::disk('public')->delete('logos/'.$branch->logo_dir);
        }

        $logo->storeAs('logos', $filename, 'public');
        $branch->update(['logo_dir' => $filename]);
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
