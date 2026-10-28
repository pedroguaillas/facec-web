<?php

namespace App\Services\Product;

use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Unidades físicas (variantes) de un producto modelo con plantilla: cada
 * moto es un producto hijo (`parent_product_id`) con sus características de
 * nivel `unidad` y un código propio. Se crean a mano (products/{id}/units) o
 * desde una línea de compra. Ver docs/caracteristicas-producto.md.
 */
class ProductUnitService
{
    /** Atributos que la unidad copia del modelo. */
    const INHERITED_ATTRIBUTES = [
        'branch_id', 'category_id', 'type_product', 'name', 'unity_id',
        'price1', 'price2', 'price3', 'iva', 'ice', 'irbpnr', 'aux_cod',
        'differentiated_iva', 'min_stock',
    ];

    public function __construct(
        private ProductDetailService $details,
        private OpeningBalanceFromStockService $openingBalance,
    ) {}

    /**
     * Código de la unidad: prefijo + valor del campo de código (ej. M5452).
     * Si la plantilla no define campo de código, se usa el código enviado.
     *
     * @param  array<string, mixed>  $details
     */
    public function codeFor(ProductTemplate $template, array $details, ?string $code = null): ?string
    {
        $codeField = $template->codeField;

        if (! $codeField instanceof ProductTemplateField) {
            return filled($code) ? trim($code) : null;
        }

        $value = $details[$codeField->key] ?? null;

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return ($template->code_prefix ?? '').trim((string) $value);
    }

    /**
     * Errores de una unidad nueva o corregida, con claves relativas:
     * `product` (el modelo no admite unidades), `details.{key}` y `code`
     * (o `details.{campo_código}` si el código se genera).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    public function validationErrors(Product $model, array $details, ?string $code = null, ?Product $ignoredUnit = null): array
    {
        $template = $model->template;

        if (! $template instanceof ProductTemplate || $model->parent_product_id) {
            return ['product' => 'El producto no tiene una plantilla de características.'];
        }

        if (! $model->isStockable()) {
            return ['product' => 'Solo un bien (no un servicio) puede tener unidades.'];
        }

        $errors = $this->details->errors($template, ProductTemplateField::LEVEL_UNIT, $details, $ignoredUnit?->id);

        if ($errors !== []) {
            return $errors;
        }

        $code = $this->codeFor($template, $details, $code);
        $attribute = $this->codeAttribute($template);

        if ($code === null) {
            return [$attribute => 'El código de la unidad es obligatorio.'];
        }

        if (mb_strlen($code) > 25) {
            return [$attribute => "El código {$code} supera los 25 caracteres que admite el SRI."];
        }

        if ($this->isCodeTaken($model->branch_id, $code, $ignoredUnit)) {
            return [$attribute => "Ya existe un producto con el código {$code}."];
        }

        return [];
    }

    /**
     * Clave relativa donde se reporta un problema con el código.
     */
    public function codeAttribute(ProductTemplate $template): string
    {
        return $template->codeField ? "details.{$template->codeField->key}" : 'code';
    }

    /**
     * Crea la unidad. Alta manual (`$openingBalance`): stock 1, que con
     * inventario entra al kardex como saldo inicial. Desde una compra, con
     * inventario nace en 0 y el stock entra con el propio movimiento de la
     * compra; sin inventario el stock se fija en 1.
     *
     * @param  array<string, mixed>  $details
     */
    public function create(Product $model, array $details, string $code, bool $inventoryEnabled, bool $openingBalance = true): Product
    {
        return DB::transaction(function () use ($model, $details, $code, $inventoryEnabled, $openingBalance) {
            $unit = Product::create([
                ...$model->only(self::INHERITED_ATTRIBUTES),
                'parent_product_id' => $model->id,
                'code' => $code,
                'stock' => $openingBalance || ! $inventoryEnabled ? 1 : 0,
            ]);

            $this->details->sync($unit, $model->template, ProductTemplateField::LEVEL_UNIT, $details);

            if ($openingBalance && $inventoryEnabled) {
                $this->openingBalance->forProduct($unit);
            }

            return $unit;
        });
    }

    /**
     * Corrige las características (y por tanto el código) de una unidad que
     * aún no se usó en comprobantes.
     *
     * @param  array<string, mixed>  $details
     */
    public function update(Product $unit, array $details, string $code): Product
    {
        return DB::transaction(function () use ($unit, $details, $code) {
            $unit->update(['code' => $code]);

            $this->details->sync($unit, $unit->parentProduct->template, ProductTemplateField::LEVEL_UNIT, $details);

            return $unit;
        });
    }

    /**
     * Líneas de compra: las que traen `unit` (datos de una moto nueva sobre
     * el producto modelo) crean la unidad y pasan a apuntar a ella.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function createFromPurchaseLines(array $lines, bool $inventoryEnabled): array
    {
        $unitLines = array_filter($lines, fn (array $line) => is_array($line['unit'] ?? null));
        $models = Product::with(['template.codeField', 'template.fields'])
            ->whereIn('id', array_map(fn (array $line) => (int) $line['product_id'], $unitLines))
            ->get()
            ->keyBy('id');

        return array_map(function (array $line) use ($inventoryEnabled, $models) {
            if (! is_array($line['unit'] ?? null)) {
                return $line;
            }

            $model = $models->get((int) $line['product_id']) ?? throw (new ModelNotFoundException)->setModel(Product::class, [$line['product_id']]);
            $code = (string) $this->codeFor($model->template, $line['unit'], $line['unit_code'] ?? null);

            $line['product_id'] = $this->create($model, $line['unit'], $code, $inventoryEnabled, openingBalance: false)->id;

            return $line;
        }, $lines);
    }

    /**
     * Unidades que una compra registró y ya no están en ella (línea quitada
     * al editar): si no se usan en otro comprobante se eliminan. Con
     * movimientos de kardex queda en soft delete (el kardex conserva la traza).
     *
     * @param  array<int, int>  $unitIds
     */
    public function discardUnused(array $unitIds): void
    {
        Product::whereIn('id', $unitIds)->whereNotNull('parent_product_id')->get()
            ->reject(fn (Product $unit) => $this->isUsed($unit))
            ->each(function (Product $unit) {
                $unit->inventories()->exists() ? $unit->delete() : $unit->forceDelete();
            });
    }

    /**
     * Compra anulada: sus unidades no vendidas salen del catálogo (soft
     * delete, stock 0). El kardex ya las revirtió con la anulación.
     */
    public function retireUnitsOf(Shop $shop): void
    {
        Product::whereIn('id', $shop->shopitems()->pluck('product_id'))
            ->whereNotNull('parent_product_id')
            ->whereNotIn('id', $this->soldUnitIdsQuery())
            ->get()
            ->each(function (Product $unit) {
                $unit->update(['stock' => 0]);
                $unit->delete();
            });
    }

    /**
     * Unidades de la compra que ya se vendieron (impiden anularla).
     *
     * @return array<int, string> Códigos.
     */
    public function soldUnitCodesOf(Shop $shop): array
    {
        return Product::whereIn('id', $shop->shopitems()->pluck('product_id'))
            ->whereNotNull('parent_product_id')
            ->whereIn('id', $this->soldUnitIdsQuery())
            ->pluck('code')
            ->all();
    }

    /**
     * Productos vendidos: en más facturas/notas de venta vigentes que notas
     * de crédito vigentes (una venta anulada o devuelta ya liberó la unidad).
     * Proformas no cuentan.
     *
     * @param  int|null  $ignoredOrderId  Venta que se edita: no cuenta.
     * @return Builder<OrderItem>
     */
    public function soldUnitIdsQuery(?int $ignoredOrderId = null)
    {
        return OrderItem::query()
            ->join('orders AS o', 'o.id', 'order_items.order_id')
            ->join('voucher_types AS vt', 'vt.id', 'o.voucher_type_id')
            ->where('o.state', '<>', VoucherStates::CANCELED)
            ->whereIn('vt.slug', [VoucherTypes::VENTA_FACTURA, VoucherTypes::VENTA_NOTA_VENTA, VoucherTypes::VENTA_NOTA_CREDITO])
            ->when($ignoredOrderId, fn ($query, int $orderId) => $query->where('o.id', '<>', $orderId))
            ->groupBy('order_items.product_id')
            ->havingRaw('SUM(CASE WHEN vt.slug = ? THEN -1 ELSE 1 END) > 0', [VoucherTypes::VENTA_NOTA_CREDITO])
            ->select('order_items.product_id');
    }

    public function isUsed(Product $unit): bool
    {
        return OrderItem::where('product_id', $unit->id)->exists()
            || ShopItem::where('product_id', $unit->id)->exists()
            || ReferralGuideItem::where('product_id', $unit->id)->exists();
    }

    private function isCodeTaken(int $branchId, string $code, ?Product $ignoredUnit): bool
    {
        $isTakenByProduct = Product::withTrashed()
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->when($ignoredUnit, fn ($query, Product $unit) => $query->whereKeyNot($unit->id))
            ->exists();

        return $isTakenByProduct || ProductPresentation::query()
            ->where('code', $code)
            ->whereHas('product', fn ($query) => $query->where('branch_id', $branchId))
            ->exists();
    }
}
