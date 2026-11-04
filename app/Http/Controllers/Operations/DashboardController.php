<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Services\Operations\OperationsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class DashboardController
{
    public function index(): View
    {
        return view('operations.dashboard');
    }

    public function data(OperationsDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->snapshot()->toArray())->header('Cache-Control', 'no-store, private');
    }
}
