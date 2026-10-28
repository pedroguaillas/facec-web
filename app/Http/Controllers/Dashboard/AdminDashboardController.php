<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\AdminDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Tablero del admin central (todo el sistema). Ver AdminDashboardService.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(AdminDashboardService $service): JsonResponse
    {
        return response()->json($service->summary());
    }
}
