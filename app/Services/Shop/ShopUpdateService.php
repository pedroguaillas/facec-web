<?php

namespace App\Services\Shop;

use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\Shop\ShopRetentionItem;
use App\Services\Product\PresentationLineResolver;
use App\Services\Product\ProductUnitService;
use App\Services\Product\VoucherInventoryService;
use App\Services\Shop\Retention\RetentionTotalsCalculator;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopUpdateService
{
    const EXCLUDED_FIELDS = ['id', 'taxes', 'pay_methods', 'app_retention', 'send', 'products'];

    public function __construct(protected Shop $shop) {}

    /**
     * `products` es opcional: si viene, reemplaza las líneas; si no, se
     * conservan. El kardex se re-sincroniza siempre (fecha o tipo pueden
     * haber cambiado); syncShop no hace nada si no hubo cambios.
     */
    public function updateShop(array $data): Shop
    {
        DB::transaction(function () use ($data) {
            if ($this->shop->update(collect($data)->except(self::EXCLUDED_FIELDS)->toArray())) {
                $this->syncRetentionItems($data);
            }

            $previousProductIds = $this->shop->shopitems()->pluck('product_id')->map(fn ($id) => (int) $id);

            if (array_key_exists('products', $data)) {
                $this->syncShopItems($data['products'] ?? []);
            }

            app(VoucherInventoryService::class)->syncShop($this->shop->fresh());

            // Motos que esta compra registró y se quitaron al editar (después del kardex, que ya las revirtió).
            if (array_key_exists('products', $data)) {
                $currentProductIds = $this->shop->shopitems()->pluck('product_id')->map(fn ($id) => (int) $id);
                app(ProductUnitService::class)->discardUnused($previousProductIds->diff($currentProductIds)->values()->all());
            }
        });

        return $this->shop->fresh();
    }

    /**
     * @param  array<int, array{product_id: int, presentation_id?: int|null, quantity: float|int|string, price: float|int|string, discount: float|int|string, iva: float|int|string}>  $products
     */
    protected function syncShopItems(array $products): void
    {
        ShopItem::where('shop_id', $this->shop->id)->delete();

        if (empty($products)) {
            return;
        }

        // Líneas con `unit` registran la moto nueva y pasan a apuntar a ella.
        $products = app(ProductUnitService::class)->createFromPurchaseLines($products, (bool) Auth::user()->company->inventory);
        $presentations = app(PresentationLineResolver::class)->resolve($products);

        $this->shop->shopitems()->createMany(array_map(fn ($product, $presentation) => [
            'product_id' => $product['product_id'],
            'presentation_id' => $presentation['presentation_id'],
            'conversion_factor' => $presentation['conversion_factor'],
            'quantity' => $product['quantity'],
            'price' => $product['price'],
            'discount' => $product['discount'],
            'iva' => $product['iva'],
        ], $products, $presentations));
    }

    protected function syncRetentionItems(array $data): void
    {
        $taxes = $data['taxes'] ?? [];

        if ($this->shop->voucherType->slug === VoucherTypes::COMPRA_NOTA_DEBITO || empty($data['app_retention']) || empty($taxes)) {
            return;
        }

        $items = array_map(fn ($t) => [
            'code' => $t['code'],
            'tax_code' => $t['tax_code'],
            'base' => $t['base'],
            'porcentage' => $t['porcentage'],
            'value' => $t['value'],
        ], $taxes);

        $items = (new RetentionTotalsCalculator)->recalculate($items, $this->shop->id);

        ShopRetentionItem::where('shop_id', $this->shop->id)->delete();
        $this->shop->shopretentionitems()->createMany($items);
    }
}
