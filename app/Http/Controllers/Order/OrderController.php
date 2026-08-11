<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderStoreRequest;
use App\Http\Requests\Order\OrderUpdateRequest;
use App\Http\Resources\OrderResources;
use App\Models\Branch;
use App\Models\MethodOfPayment;
use App\Models\Order\Order;
use App\Models\Product\IvaTax;
use App\Services\Order\OrderPdfService;
use App\Services\Order\OrderShowService;
use App\Services\Order\OrderStoreService;
use App\Services\Order\OrderUpdateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $orders = Order::join('customers AS c', 'c.id', 'orders.customer_id')
            ->select('orders.*', 'c.name', 'c.email')
            ->latest('orders.created_at')
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('orders.serie', 'LIKE', "%{$escaped}%")
                        ->orWhere('c.name', 'LIKE', "%{$escaped}%");
                });
            })
            ->when($request->filled('date'), function ($query) use ($request) {
                $query->whereDate('orders.date', $request->input('date'));
            })
            ->paginate($paginate)
            ->withQueryString();

        return OrderResources::collection($orders);
    }

    public function create(): JsonResponse
    {
        return response()->json([
            ...$this->emisionData(),
        ]);
    }

    public function store(OrderStoreRequest $request, OrderStoreService $service): JsonResponse
    {
        $order = $service->createOrder($request->validated());

        return response()->json($order, 201);
    }

    public function edit(Order $order, OrderShowService $service): JsonResponse
    {
        return response()->json([
            ...$service->getOrderDetail($order),
            ...$this->emisionData(),
        ]);
    }

    public function update(OrderUpdateRequest $request, Order $order, OrderUpdateService $service): JsonResponse
    {
        $service->updateOrder($order, $request->validated());

        return response()->json($order->fresh());
    }

    public function pdf(Order $order, OrderPdfService $service)
    {
        [$pdf] = $service->buildPdf($order->id);

        return $pdf->stream("{$order->serie}.pdf");
    }

    public function printf(Order $order, OrderPdfService $service)
    {
        return $service->buildPrintPdf($order->id)->stream("{$order->serie}.pdf");
    }

    /**
     * @return array{points: Collection, methodOfPayments: Collection, tourism: bool, ivaTaxes: Collection}
     */
    private function emisionData(): array
    {
        $company = Auth::user()->company;

        return [
            'points' => Branch::selectRaw("branches.id AS branch_id, LPAD(store, 3, '0') AS store, ep.id, LPAD(point, 3, '0') AS point, ep.invoice, ep.creditnote, recognition")
                ->leftJoin('emision_points AS ep', 'branches.id', 'ep.branch_id')
                ->where('branches.company_id', $company->id)
                ->get(),
            'methodOfPayments' => MethodOfPayment::query()
                ->whereNotIn('code', [15, 17, 18, 21])
                ->get(),
            'tourism' => $this->isTourism($company),
            'ivaTaxes' => IvaTax::query()
                ->where('state', 'active')
                ->get(['code', 'percentage']),
        ];
    }

    private function isTourism($company): bool
    {
        if (! $company->base8 || $company->tourism_from === null || $company->tourism_to === null) {
            return false;
        }

        $now = Carbon::now();

        return $now->isAfter($company->tourism_from) && $now->isBefore($company->tourism_to);
    }
}
