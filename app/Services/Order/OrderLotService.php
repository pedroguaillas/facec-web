<?php

namespace App\Services\Order;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Lot;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\StaticClasses\VoucherStates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class OrderLotService
{
    public const MAX_ROWS = 2000;

    private Company $company;

    private Branch $branch;

    public function __construct(
        private readonly OrderLotExcelReader $excelReader,
    ) {
        $this->company = Auth::user()->company;
        $this->branch = Branch::where('company_id', $this->company->id)
            ->orderBy('created_at')->first();
    }

    /**
     * Crea el lote de facturas y encola cada una para firma/envío a SRI.
     *
     * @throws RuntimeException si el Excel tiene filas incompletas, supera
     *                          MAX_ROWS, o referencia clientes/productos no registrados
     */
    public function store(UploadedFile $file): void
    {
        $excelData = $this->excelReader->read($file);

        [$identifications, $codes] = $this->validateRows($excelData);

        $customers = $this->resolveCustomers($identifications);
        $products = $this->resolveProducts($codes);

        $emisionPoint = EmisionPoint::where('branch_id', $this->branch->id)->first();
        $date = Carbon::now();

        $lot = Lot::create([
            'emision_point_id' => $emisionPoint->id,
            'serie' => $this->buildSerie($emisionPoint, $emisionPoint->lot),
            'authorization' => '',
            'state' => VoucherStates::SAVED,
            'date' => $date->toDateString(),
            'voucher_type' => 1,
        ]);
        $emisionPoint->lot++;
        $emisionPoint->save();

        [$orderRows, $orderItemsByRow] = $this->buildOrderRows($excelData, $customers, $products, $lot, $emisionPoint, $date->toDateString());

        $orders = $this->branch->orders()->createMany($orderRows)->fresh();
        $emisionPoint->save();

        $this->insertOrderItems($orders, $orderItemsByRow);
        $this->insertAditionals($orders);
        $this->dispatchProcessing($orders);
    }

    /**
     * @param  array<int, array<int, mixed>>  $excelData
     * @return array{0: array<int, string>, 1: array<int, string>} [identificaciones, codigos] únicos
     */
    private function validateRows(array $excelData): array
    {
        $identifications = [];
        $codes = [];
        $limit = 0;

        foreach ($excelData as $item) {
            if ($item[0] === null || $item[2] === null || $item[3] === null || $item[4] === null) {
                throw new RuntimeException('Hay celdas en blanco');
            }

            $identifications[] = $item[0];
            $codes[] = $item[2];
            $limit++;
        }

        if ($limit > self::MAX_ROWS) {
            throw new RuntimeException('Limite maximo permite '.self::MAX_ROWS.' registros');
        }

        return [array_unique($identifications), array_unique($codes)];
    }

    /** @param  array<int, string>  $identifications */
    private function resolveCustomers(array $identifications): array
    {
        $customers = Customer::select('id', 'identication')
            ->whereIn('identication', $identifications)
            ->where('branch_id', $this->branch->id)->get();

        if (count($identifications) > $customers->count()) {
            throw new RuntimeException('No esta registrado todos los clientes');
        }

        return json_decode(json_encode($customers));
    }

    /** @param  array<int, string>  $codes */
    private function resolveProducts(array $codes): array
    {
        $products = Product::select('products.id', 'products.code', 'iva_taxes.percentage', 'iva_taxes.code AS iva')
            ->join('iva_taxes', 'products.iva', 'iva_taxes.code')
            ->whereIn('products.code', $codes)
            ->where('products.branch_id', $this->branch->id)->get();

        if (count($codes) > $products->count()) {
            throw new RuntimeException('No esta registrado todos los productos');
        }

        return json_decode(json_encode($products));
    }

    private function buildSerie(EmisionPoint $emisionPoint, int $sequence): string
    {
        return str_pad($this->branch->store, 3, '0', STR_PAD_LEFT).'-'.str_pad($emisionPoint->point, 3, '0', STR_PAD_LEFT).'-'.str_pad($sequence, 9, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<int, mixed>>  $excelData
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} [filas de orders, filas de order_items] en el mismo orden
     */
    private function buildOrderRows(array $excelData, array $customers, array $products, Lot $lot, EmisionPoint $emisionPoint, string $date): array
    {
        $orders = [];
        $orderItems = [];

        foreach ($excelData as $productData) {
            $customer = array_values(array_filter($customers, fn ($item) => $item->identication === $productData[0]));
            $product = array_values(array_filter($products, fn ($item) => $item->code === $productData[2]));

            $subTotal = $productData[3] * $productData[4];
            $product = $product[0];
            $iva = $subTotal * $product->percentage * 0.01;

            $input = [
                'date' => $date,
                'sub_total' => $subTotal,
                'serie' => $this->buildSerie($emisionPoint, $emisionPoint->invoice),
                'customer_id' => $customer[0]->id,
                'lot_id' => $lot->id,
                'total' => $subTotal + $iva,
                // Consumidor final (identificación 9999999999999): forma de pago 01
                // (SIN UTILIZACIÓN DEL SISTEMA FINANCIERO) en vez de la de la empresa —
                // el Excel del lote no trae columna de forma de pago por fila.
                'pay_method' => $customer[0]->identication === '9999999999999' ? '01' : $this->company->pay_method,
            ];

            $input["base{$product->percentage}"] = $subTotal;
            if ($product->percentage !== 0) {
                $input["iva{$product->percentage}"] = $iva;
            }

            $orders[] = $input;
            $orderItems[] = [
                'product_id' => $product->id,
                'quantity' => $productData[3],
                'price' => $productData[4],
                'iva' => $product->iva,
            ];
            $emisionPoint->invoice++;
        }

        return [$orders, $orderItems];
    }

    /**
     * Insert en batch en vez de un create() por fila: con lotes grandes (hasta
     * MAX_ROWS filas), insertar uno por uno acá (sumado al dispatch() por orden)
     * podía superar max_execution_time en producción y cortar el request a la
     * mitad (502), dejando datos parcialmente creados/encolados.
     *
     * @param  EloquentCollection<int, Order>  $orders
     * @param  array<int, array<string, mixed>>  $orderItemsByRow
     */
    private function insertOrderItems(EloquentCollection $orders, array $orderItemsByRow): void
    {
        $now = now();
        $rows = [];

        foreach ($orders as $i => $order) {
            $rows[] = array_merge($orderItemsByRow[$i], [
                'order_id' => $order->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        OrderItem::insert($rows);
    }

    /** @param  EloquentCollection<int, Order>  $orders */
    private function insertAditionals(EloquentCollection $orders): void
    {
        $now = now();
        $rows = [];

        foreach ($orders as $order) {
            $rows[] = array_merge(Order::REQUIRED_ADITIONAL, [
                'order_id' => $order->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        OrderAditional::insert($rows);
    }

    /**
     * Cada comprobante se encola por separado (mismo mecanismo que el resto del
     * módulo): firmar+enviar+autorizar todas las órdenes síncronas en el request
     * bloquearía la respuesta HTTP hasta minutos. Van a la cola `lots`, separada
     * de la cola `default` de comprobantes normales.
     *
     * @param  EloquentCollection<int, Order>  $orders
     */
    private function dispatchProcessing(EloquentCollection $orders): void
    {
        foreach ($orders as $order) {
            ProcessVoucherJob::dispatch('order', $order->id, $this->company->id)->afterCommit()->onQueue('lots');
        }
    }
}
