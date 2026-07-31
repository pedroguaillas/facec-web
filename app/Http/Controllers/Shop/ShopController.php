<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ShopStoreRequest;
use App\Http\Requests\Shop\ShopUpdateRequest;
use App\Models\Branch;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Services\Shop\ShopLcPdfService;
use App\Services\Shop\ShopStoreService;
use App\Services\Shop\ShopUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

        $shops = Shop::join('providers AS p', 'p.id', 'shops.provider_id')
            ->select('shops.*', 'p.id AS provider_pk', 'p.name AS provider_name')
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
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Shop $shop) => [
                'id' => $shop->id,
                'serie' => $shop->serie,
                'voucher_type' => $shop->voucher_type,
                'date' => $shop->date,
                'total' => $shop->total,
                'state' => $shop->state,
                'extra_detail' => $shop->extra_detail,
                'send_mail_set_purchase' => $shop->send_mail_set_purchase,
                'provider' => [
                    'id' => $shop->provider_pk,
                    'name' => $shop->provider_name,
                ],
            ]);

        return Inertia::render('shops/Index', [
            'shops' => [
                'data' => $shops->items(),
                'links' => $shops->linkCollection(),
                'meta' => [
                    'current_page' => $shops->currentPage(),
                    'last_page' => $shops->lastPage(),
                    'per_page' => $shops->perPage(),
                    'total' => $shops->total(),
                    'from' => $shops->firstItem(),
                    'to' => $shops->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
                'date' => $request->input('date'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('shops/Create', $this->emisionData());
    }

    public function store(ShopStoreRequest $request, ShopStoreService $service): RedirectResponse
    {
        $shop = $service->createShop($request->validated());

        return redirect()
            ->route('shops.edit', $shop)
            ->with('success', 'Compra creada con éxito.');
    }

    public function edit(Shop $shop): Response
    {
        $isSettlement = (int) $shop->voucher_type === 3;

        return Inertia::render('shops/Edit', [
            'shop' => collect($shop->toArray())
                ->filter(fn ($value) => $value !== null)
                ->all(),
            'shop_items' => $isSettlement ? $this->getShopItems($shop->id) : [],
            'products' => $isSettlement ? $this->getProducts($shop->id) : [],
            'provider' => Provider::where('id', $shop->provider_id)->first(['id', 'name', 'identication']),
            ...$this->emisionData(),
        ]);
    }

    public function update(ShopUpdateRequest $request, Shop $shop): RedirectResponse
    {
        (new ShopUpdateService($shop))->updateShop($request->validated());

        return redirect()
            ->route('shops.edit', $shop)
            ->with('success', 'Compra actualizada con éxito.');
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

    /**
     * @return array{points: Collection}
     */
    private function emisionData(): array
    {
        $company = Auth::user()->company;

        return [
            'points' => Branch::selectRaw("branches.id AS branch_id, LPAD(store, 3, '0') AS store, ep.id, LPAD(point, 3, '0') AS point, ep.settlementonpurchase, recognition")
                ->leftJoin('emision_points AS ep', 'branches.id', 'ep.branch_id')
                ->where('branches.company_id', $company->id)
                ->get(),
        ];
    }
}
