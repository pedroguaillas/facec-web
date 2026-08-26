<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ShopStoreRequest;
use App\Http\Requests\Shop\ShopUpdateRequest;
use App\Http\Resources\ShopResources;
use App\Models\Branch;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\Shop\ShopRetentionItem;
use App\Models\Tax;
use App\Services\Shop\ShopLcPdfService;
use App\Services\Shop\ShopStoreService;
use App\Services\Shop\ShopUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $shops = Shop::join('providers AS p', 'p.id', 'shops.provider_id')
            ->select('shops.*', 'p.name', 'p.email')
            ->withSum('shopretentionitems as retention_sum', 'value')
            ->latest('shops.created_at')
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('shops.serie', 'LIKE', "%{$escaped}%")
                        ->orWhere('p.name', 'LIKE', "%{$escaped}%");
                });
            })
            ->when($request->filled('date'), function ($query) use ($request) {
                $query->whereDate('shops.date', $request->input('date'));
            })
            ->paginate($paginate)
            ->withQueryString();

        return ShopResources::collection($shops);
    }

    public function create(): JsonResponse
    {
        return response()->json([
            'taxes' => Tax::all(),
            ...$this->emisionData(),
        ]);
    }

    public function store(ShopStoreRequest $request, ShopStoreService $service): JsonResponse
    {
        $shop = $service->createShop($request->validated());

        return response()->json($shop, 201);
    }

    public function edit(Shop $shop): JsonResponse
    {
        $isSettlement = (int) $shop->voucher_type === 3;

        return response()->json([
            'shop' => collect($shop->toArray())
                ->filter(fn ($value) => $value !== null)
                ->all(),
            'shop_items' => $isSettlement ? $this->getShopItems($shop->id) : [],
            'products' => $isSettlement ? $this->getProducts($shop->id) : [],
            'shopretentionitems' => $this->getShopRetentionItems($shop->id),
            'taxes' => Tax::all(),
            'provider' => Provider::where('id', $shop->provider_id)->first(['id', 'name', 'identication']),
            ...$this->emisionData(),
        ]);
    }

    public function update(ShopUpdateRequest $request, Shop $shop): JsonResponse
    {
        (new ShopUpdateService($shop))->updateShop($request->validated());

        return response()->json([
            'message' => 'Compra actualizada con éxito.',
            'data' => $shop->fresh(),
        ]);
    }

    public function pdf(Shop $shop, ShopLcPdfService $service)
    {
        // Solo la Liquidación de Compra (voucher_type 3) es un comprobante electrónico propio;
        // factura/nota de venta/nota de débito son documentos externos, sin PDF generado por el sistema.
        abort_unless((int) $shop->voucher_type === 3, 404);

        return $service->stream($shop->id);
    }

    private function getShopItems(int $shopId)
    {
        return ShopItem::join('products AS p', 'shop_items.product_id', 'p.id')
            ->select('shop_items.*', 'p.name', 'p.code')
            ->where('shop_id', $shopId)
            ->get();
    }

    private function getProducts(int $shopId)
    {
        return Product::join('shop_items AS si', 'si.product_id', 'products.id')
            ->select('products.*')
            ->where('si.shop_id', $shopId)
            ->get();
    }

    private function getShopRetentionItems(int $shopId)
    {
        return ShopRetentionItem::select('shop_retention_items.*', 'taxes.conception AS tax_name')
            ->join('taxes', 'taxes.code', 'shop_retention_items.tax_code')
            ->where('shop_id', $shopId)
            ->get();
    }

    /**
     * @return array{points: Collection}
     */
    private function emisionData(): array
    {
        $company = Auth::user()->company;

        return [
            'points' => Branch::selectRaw("branches.id AS branch_id, LPAD(store, 3, '0') AS store, ep.id, LPAD(point, 3, '0') AS point, ep.retention, ep.settlementonpurchase, recognition")
                ->leftJoin('emision_points AS ep', 'branches.id', 'ep.branch_id')
                ->where('branches.company_id', $company->id)
                ->get(),
        ];
    }
}
