<?php

namespace App\Http\Controllers\ReferralGuide;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferralGuide\ReferralGuideStoreRequest;
use App\Http\Requests\ReferralGuide\ReferralGuideUpdateRequest;
use App\Http\Resources\CarrierResources;
use App\Http\Resources\CustomerResources;
use App\Http\Resources\ProductResources;
use App\Http\Resources\ReferralGuideResources;
use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Customer;
use App\Models\Product\Product;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Services\ReferralGuide\ReferralGuideStoreService;
use App\Services\ReferralGuide\ReferralGuideUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ReferralGuideController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginate = (int) $request->input('paginate', 15);

        $referralguides = ReferralGuide::join('carriers AS ca', 'ca.id', 'carrier_id')
            ->join('customers AS c', 'c.id', 'customer_id')
            ->selectRaw(
                'referral_guides.*,'.
                'c.name,'.
                'ca.name AS carrier_name,'.
                "DATE_FORMAT(date_start, '%d-%m-%Y') as date_start,".
                "DATE_FORMAT(date_end, '%d-%m-%Y') as date_end"
            )
            ->latest('referral_guides.created_at')
            ->paginate($paginate)
            ->withQueryString();

        return ReferralGuideResources::collection($referralguides);
    }

    public function create(): JsonResponse
    {
        return response()->json($this->emisionData()['points']);
    }

    public function store(ReferralGuideStoreRequest $request, ReferralGuideStoreService $service): JsonResponse
    {
        $referralguide = $service->createReferralGuide($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Guía de remisión creada con éxito.',
            'referralguide' => $referralguide,
        ], 201);
    }

    public function show(ReferralGuide $referralguide): JsonResponse
    {
        $customers = Customer::where('id', $referralguide->customer_id)->get();
        $carriers = Carrier::where('id', $referralguide->carrier_id)->get();

        return response()->json([
            'referralguide' => collect($referralguide->toArray())
                ->filter(fn ($value) => $value !== null)
                ->all(),
            'referralguide_items' => $this->getItems($referralguide->id),
            'customers' => CustomerResources::collection($customers),
            'carriers' => CarrierResources::collection($carriers),
            'products' => ProductResources::collection($this->getProducts($referralguide->id)),
            ...$this->emisionData(),
        ]);
    }

    public function update(ReferralGuideUpdateRequest $request, ReferralGuide $referralguide): JsonResponse
    {
        (new ReferralGuideUpdateService($referralguide))->updateReferralGuide($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Guía de remisión actualizada con éxito.',
            'referralguide' => $referralguide->fresh(),
        ]);
    }

    public function pdf(int $id): mixed
    {
        return $this->buildPdf($id);
    }

    private function buildPdf(int $id)
    {
        $movement = ReferralGuide::join('customers AS c', 'customer_id', 'c.id')
            ->join('carriers AS ca', 'carrier_id', 'ca.id')
            ->select('referral_guides.*', 'c.*', 'ca.identication AS ca_identication', 'ca.name AS ca_name', 'ca.license_plate')
            ->where('referral_guides.id', $id)
            ->firstOrFail();

        $movement->voucher_type = 6;

        $movement_items = ReferralGuideItem::join('products AS p', 'p.id', 'product_id')
            ->select('quantity', 'name', 'code')
            ->where('referral_guide_id', $id)
            ->get();

        $company = Auth::user()->company;
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = Branch::where('company_id', $company->id)
            ->where('store', (int) substr($movement->serie, 0, 3))
            ->first()
            ?? Branch::where('company_id', $company->id)->orderBy('created_at')->first();

        return app('dompdf.wrapper')
            ->loadView('vouchers.referralguide', compact('movement', 'company', 'branch', 'movement_items'))
            ->stream();
    }

    private function getItems(int $id)
    {
        return Product::join('referral_guide_items AS rgi', 'product_id', 'products.id')
            ->select('rgi.id', 'quantity', 'name', 'product_id')
            ->where('referral_guide_id', $id)
            ->get()
            ->map(function ($item) {
                $item->quantity = floatval($item->quantity);

                return $item;
            });
    }

    private function getProducts(int $id)
    {
        return Product::join('referral_guide_items AS rgi', 'product_id', 'products.id')
            ->select('products.*')
            ->where('referral_guide_id', $id)
            ->get();
    }

    /**
     * @return array{points: Collection}
     */
    private function emisionData(): array
    {
        $company = Auth::user()->company;

        return [
            'points' => Branch::selectRaw("branches.id AS branch_id, LPAD(store, 3, '0') AS store, ep.id, LPAD(point, 3, '0') AS point, ep.referralguide, recognition")
                ->leftJoin('emision_points AS ep', 'branches.id', 'ep.branch_id')
                ->where('branches.company_id', $company->id)
                ->get(),
        ];
    }
}
