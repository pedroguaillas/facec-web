<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Provider;
use App\Models\Shop\Shop;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $company = $user->company;

        $active = true;
        $expired = null;
        $certExpiration = null;
        $income = [];
        $expenses = [];
        $countOrders = 0;
        $countShops = 0;
        $countCustomers = 0;
        $countProviders = 0;
        $newCustomersThisMonth = 0;
        $newProvidersThisMonth = 0;
        $recentOrders = [];

        $branch = $company
            ? Branch::where('company_id', $company->id)->orderBy('created_at')->first()
            : null;

        if ($branch) {
            $active = $company->active_voucher;
            $expired = $company->expired;
            $certExpiration = $company->sign_valid_to;

            $income = Order::selectRaw(
                "SUM(total) as total, DATE_FORMAT(date, '%m-%Y') AS name, DATE_FORMAT(date, '%Y%m') AS period"
            )
                ->groupBy('name', 'period')
                ->orderBy('period', 'desc')
                ->take(5)
                ->get()
                ->reverse()
                ->values();

            $expenses = Shop::selectRaw(
                "SUM(total) as total, DATE_FORMAT(date, '%m-%Y') AS name, DATE_FORMAT(date, '%Y%m') AS period"
            )
                ->groupBy('name', 'period')
                ->orderBy('period', 'desc')
                ->take(5)
                ->get()
                ->reverse()
                ->values();

            $countOrders = Order::count();
            $countShops = Shop::count();
            $countCustomers = Customer::where('type_identification', '<>', 'cf')->count();
            $countProviders = Provider::count();

            $startOfMonth = Carbon::now()->startOfMonth();
            $newCustomersThisMonth = Customer::where('type_identification', '<>', 'cf')
                ->where('created_at', '>=', $startOfMonth)
                ->count();
            $newProvidersThisMonth = Provider::where('created_at', '>=', $startOfMonth)->count();

            $recentOrders = Order::join('customers AS c', 'c.id', 'orders.customer_id')
                ->select('orders.id', 'orders.serie', 'orders.voucher_type', 'orders.date', 'orders.total', 'orders.state', 'c.name AS customer_name')
                ->latest('orders.created_at')
                ->take(5)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'serie' => $order->serie,
                    'voucher_type' => $order->voucher_type,
                    'date' => $order->date,
                    'total' => $order->total,
                    'state' => $order->state,
                    'customer' => ['name' => $order->customer_name],
                ]);
        }

        return Inertia::render('Dashboard', [
            'active' => $active,
            'expired' => $expired,
            'certExpiration' => $certExpiration,
            'income' => $income,
            'expenses' => $expenses,
            'counts' => [
                'orders' => $countOrders,
                'shops' => $countShops,
                'customers' => $countCustomers,
                'providers' => $countProviders,
            ],
            'newThisMonth' => [
                'customers' => $newCustomersThisMonth,
                'providers' => $newProvidersThisMonth,
            ],
            'recentOrders' => $recentOrders,
        ]);
    }
}
