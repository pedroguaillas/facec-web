<?php

namespace App\Http\Controllers\AccountsPayable;

use App\Http\Controllers\Controller;
use App\Models\Shop\Shop;
use App\StaticClasses\PaymentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class OverdueController extends Controller
{
    /**
     * Compras a crédito/anticipo vencidas (due_date < hoy) con saldo pendiente,
     * agrupadas por proveedor — para la pantalla "Cartera vencida".
     */
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        $shops = Shop::with('provider')
            ->whereIn('payment_type', [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereColumn('paid', '<', 'total')
            ->get();

        $parties = $shops->groupBy('provider_id')->map(function ($group) use ($today) {
            $provider = $group->first()->provider;

            $documents = $group->map(fn (Shop $shop) => [
                'shop_id' => $shop->id,
                'serie' => $shop->serie,
                'due_date' => $shop->due_date->format('Y-m-d'),
                'days_overdue' => (int) $shop->due_date->diffInDays($today),
                'pending' => round((float) $shop->total - (float) $shop->paid, 2),
            ])->values();

            return [
                'provider_id' => $provider->id,
                'provider_name' => $provider->name,
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
