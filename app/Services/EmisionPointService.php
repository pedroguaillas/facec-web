<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;

class EmisionPointService
{
    public function __construct(private BranchService $branchService) {}

    public function create(array $attributes)
    {
        return EmisionPoint::create($attributes);
    }

    public function getByAttributes(array $attributes = [], array $with = [], array $columns = ['*'])
    {
        $query = EmisionPoint::select($columns)->with($with);

        foreach ($attributes as $field => $value) {
            // Si el campo contiene un punto, es una relación (ej: branch.company_id)
            if (str_contains($field, '.')) {
                [$relation, $column] = explode('.', $field);
                $query->whereHas($relation, function ($q) use ($column, $value) {
                    $q->where($column, $value);
                });
            } else {
                $query->where($field, $value);
            }
        }

        return $query->get();
    }

    public function checkAndCreateFirstPoint(Company $company): void
    {
        $branch = $this->branchService->getOnlyIfIsUnique(
            ['company_id' => $company->id],
            ['emisionPoints']
        );

        if ($branch && $branch->emisionPoints->isEmpty()) {
            $this->autoCreatePoint($branch);
        }
    }

    // todo: moder lógica al servicio de branchService
    private function autoCreatePoint(Branch $branch): void
    {
        $store = (int) $branch->store;
        $emisionPoint = new EmisionPoint;
        $point = null;

        $configs = [
            [
                'model' => Order::class,
                'type' => 'invoice',
                'voucher' => 1,
                'col' => 'serie',
            ],
            [
                'model' => Order::class,
                'type' => 'creditnote',
                'voucher' => 4,
                'col' => 'serie',
            ],
            [
                'model' => Shop::class,
                'type' => 'settlementonpurchase',
                'voucher' => 3,
                'col' => 'serie',
            ],
            [
                'model' => Shop::class,
                'type' => 'retention',
                'voucher' => null,
                'col' => 'serie_retencion',
            ],
            [
                'model' => ReferralGuide::class,
                'type' => 'referralguide',
                'voucher' => null,
                'col' => 'serie',
            ],
        ];

        foreach ($configs as $conf) {
            $query = $conf['model']::select($conf['col'].' AS serie')
                ->where('branch_id', $branch->id);

            if ($conf['voucher']) {
                $query->where('voucher_type', $conf['voucher']);
            }

            $doc = $query->latest('created_at')->first();

            if (! $this->validSerie($emisionPoint, $doc, $conf['type'], $store, $point)) {
                return;
            }
        }

        if ($point !== null) {
            $emisionPoint->branch_id = $branch->id;
            $emisionPoint->point = sprintf('%03d', $point); // formato 001
            $emisionPoint->save();
        }
    }

    private function validSerie($emisionPoint, $doc, $type, $store, &$point): bool
    {
        if ($doc) {
            $serie = explode('-', $doc->serie);
            if (count($serie) < 3) {
                return false;
            }

            $docStore = (int) $serie[0];
            $docPoint = (int) $serie[1];
            $nextSequence = (int) $serie[2] + 1;

            // Validar que el establecimiento coincida y el punto sea consistente
            if ($docStore === $store && ($point === null || $point === $docPoint)) {
                $point = $docPoint;
                $emisionPoint->{$type} = $nextSequence;

                return true;
            }

            return false;
        }

        // Si no hay documentos previos, iniciar la secuencia en 1
        $point ??= 1;
        $emisionPoint->{$type} = 1;

        return true;
    }
}
