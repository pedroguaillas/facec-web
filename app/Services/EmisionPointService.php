<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Models\Order\Order;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

class EmisionPointService
{
    /**
     * Columnas legacy de `emision_points` que el frontend de Configuración
     * sigue usando como contrato, y el tipo de comprobante cuya secuencia
     * real (`emision_point_sequences.next_number`) representan.
     *
     * @var array<string, string>
     */
    public const LEGACY_SEQUENCE_COLUMNS = [
        'invoice' => VoucherTypes::VENTA_FACTURA,
        'creditnote' => VoucherTypes::VENTA_NOTA_CREDITO,
        'retention' => VoucherTypes::RETENCION,
        'referralguide' => VoucherTypes::GUIA_REMISION,
        'settlementonpurchase' => VoucherTypes::COMPRA_LIQUIDACION_COMPRA,
    ];

    public function __construct(private BranchService $branchService) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): EmisionPoint
    {
        return DB::transaction(function () use ($attributes) {
            $emisionPoint = EmisionPoint::create($attributes);
            $this->syncSequences($emisionPoint, $attributes);

            return $this->withSequences($emisionPoint);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(EmisionPoint $emisionPoint, array $attributes): EmisionPoint
    {
        return DB::transaction(function () use ($emisionPoint, $attributes) {
            $emisionPoint->update($attributes);
            $this->syncSequences($emisionPoint, $attributes);

            return $this->withSequences($emisionPoint);
        });
    }

    /**
     * Reemplaza los valores de las columnas legacy por el `next_number` real
     * de `emision_point_sequences`, que es lo que usa VoucherSequenceService
     * al emitir. Sin fila de secuencia se deja el valor legacy (la primera
     * reserva la crea en 1).
     */
    public function withSequences(EmisionPoint $emisionPoint): EmisionPoint
    {
        $nextNumbers = EmisionPointSequence::query()
            ->join('voucher_types', 'voucher_types.id', '=', 'emision_point_sequences.voucher_type_id')
            ->where('emision_point_id', $emisionPoint->id)
            ->whereIn('voucher_types.slug', self::LEGACY_SEQUENCE_COLUMNS)
            ->pluck('emision_point_sequences.next_number', 'voucher_types.slug');

        foreach (self::LEGACY_SEQUENCE_COLUMNS as $column => $slug) {
            if ($nextNumbers->has($slug)) {
                $emisionPoint->setAttribute($column, (int) $nextNumbers[$slug]);
            }
        }

        return $emisionPoint;
    }

    /**
     * Escribe en `emision_point_sequences` los secuenciales enviados con el
     * nombre de columna legacy. Debe invocarse dentro de una transacción.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncSequences(EmisionPoint $emisionPoint, array $attributes): void
    {
        foreach (self::LEGACY_SEQUENCE_COLUMNS as $column => $slug) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $voucherTypeId = VoucherType::where('slug', $slug)->value('id');

            $sequence = EmisionPointSequence::where('emision_point_id', $emisionPoint->id)
                ->where('voucher_type_id', $voucherTypeId)
                ->lockForUpdate()
                ->first();

            $nextNumber = (int) $attributes[$column];

            if ($sequence === null) {
                EmisionPointSequence::create([
                    'emision_point_id' => $emisionPoint->id,
                    'voucher_type_id' => $voucherTypeId,
                    'next_number' => $nextNumber,
                ]);
            } elseif ($sequence->next_number !== $nextNumber) {
                $sequence->update(['next_number' => $nextNumber]);
            }
        }
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
        $point = null;

        $configs = [
            [
                'model' => Order::class,
                'slug' => VoucherTypes::VENTA_FACTURA,
                'col' => 'serie',
                'filterBySlug' => true,
            ],
            [
                'model' => Order::class,
                'slug' => VoucherTypes::VENTA_NOTA_CREDITO,
                'col' => 'serie',
                'filterBySlug' => true,
            ],
            [
                'model' => Shop::class,
                'slug' => VoucherTypes::COMPRA_LIQUIDACION_COMPRA,
                'col' => 'serie',
                'filterBySlug' => true,
            ],
            [
                'model' => Shop::class,
                'slug' => VoucherTypes::RETENCION,
                'col' => 'serie_retencion',
                'filterBySlug' => false,
            ],
            [
                'model' => ReferralGuide::class,
                'slug' => VoucherTypes::GUIA_REMISION,
                'col' => 'serie',
                'filterBySlug' => false,
            ],
        ];

        $nextNumbers = [];

        foreach ($configs as $conf) {
            $query = $conf['model']::select($conf['col'].' AS serie')
                ->where('branch_id', $branch->id);

            if ($conf['filterBySlug']) {
                $query->where('voucher_type_id', VoucherType::where('slug', $conf['slug'])->value('id'));
            }

            $doc = $query->latest('created_at')->first();
            $nextSequence = $this->nextSequenceNumber($doc, $store, $point);

            if ($nextSequence === null) {
                return;
            }

            $nextNumbers[$conf['slug']] = $nextSequence;
        }

        if ($point === null) {
            return;
        }

        DB::transaction(function () use ($branch, $point, $nextNumbers) {
            $emisionPoint = EmisionPoint::create([
                'branch_id' => $branch->id,
                'point' => sprintf('%03d', $point), // formato 001
            ]);

            foreach ($nextNumbers as $slug => $nextNumber) {
                EmisionPointSequence::create([
                    'emision_point_id' => $emisionPoint->id,
                    'voucher_type_id' => VoucherType::where('slug', $slug)->value('id'),
                    'next_number' => $nextNumber,
                ]);
            }
        });
    }

    private function nextSequenceNumber($doc, int $store, ?int &$point): ?int
    {
        if ($doc) {
            $serie = explode('-', $doc->serie);
            if (count($serie) < 3) {
                return null;
            }

            $docStore = (int) $serie[0];
            $docPoint = (int) $serie[1];
            $nextSequence = (int) $serie[2] + 1;

            // Validar que el establecimiento coincida y el punto sea consistente
            if ($docStore === $store && ($point === null || $point === $docPoint)) {
                $point = $docPoint;

                return $nextSequence;
            }

            return null;
        }

        // Si no hay documentos previos, iniciar la secuencia en 1
        $point ??= 1;

        return 1;
    }
}
