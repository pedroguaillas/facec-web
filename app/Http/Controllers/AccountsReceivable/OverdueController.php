<?php

namespace App\Http\Controllers\AccountsReceivable;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\StaticClasses\PaymentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class OverdueController extends Controller
{
    /**
     * Ventas a crédito/anticipo vencidas (due_date < hoy) con saldo pendiente,
     * agrupadas por cliente — para la pantalla "Cartera vencida".
     */
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        $orders = Order::with('customer')
            ->whereIn('payment_type', [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereColumn('paid', '<', 'total')
            ->get()
            // Una retención puede cubrir el resto de la factura.
            ->filter(fn (Order $order) => $order->pendingBalance() > 0);

        $parties = $orders->groupBy('customer_id')->map(function ($group) use ($today) {
            $customer = $group->first()->customer;

            $documents = $group->map(fn (Order $order) => [
                'order_id' => $order->id,
                'serie' => $order->serie,
                'due_date' => $order->due_date->format('Y-m-d'),
                'days_overdue' => (int) $order->due_date->diffInDays($today),
                'pending' => $order->pendingBalance(),
            ])->values();

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'total_overdue' => round($documents->sum('pending'), 2),
                'documents' => $documents,
            ];
        })->values();

        return response()->json([
            'total_overdue' => round($parties->sum('total_overdue'), 2),
            'parties' => $parties,
        ]);
    }
}
