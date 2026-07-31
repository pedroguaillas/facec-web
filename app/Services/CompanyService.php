<?php

namespace App\Services;

use App\Models\Company;

class CompanyService
{
    public function __construct(private SriResolveNameService $sriService) {}

    public function searchByIdentificationSRI(string $identification)
    {
        return $this->sriService->searchByIdentificationSRI($identification);
    }

    public function getAll(?string $search = null, int $perPage = 15)
    {
        return Company::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ruc', 'LIKE', "%$search%")
                        ->orWhere('company', 'LIKE', "%$search%");
                });
            })
            ->orderBy('id', 'DESC')
            ->paginate($perPage);
    }

    public function update(Company $company, array $attributes)
    {
        $company->update($attributes);

        return $company->fresh();
    }

    public function create(array $attributes)
    {
        return Company::create($attributes);
    }

    public function findByAttributes(array $attributes)
    {
        return Company::where($attributes)->firstOrFail();
    }
}
