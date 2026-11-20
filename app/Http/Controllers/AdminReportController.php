<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /**
     * Operator Admin Report showing user spend, provider cost & profit reconciliation.
     */
    public function index(Request $request, PixelforgeClient $client): Response
    {
        // 1. Daily Aggregates from local database
        $dailyRecords = Generation::where('status', 'succeeded')
            ->select(
                DB::raw("date(created_at) as report_date"),
                DB::raw("count(*) as total_generations"),
                DB::raw("sum(user_charged_usd) as total_user_spend"),
                DB::raw("sum(provider_cost_usd) as total_provider_cost"),
                DB::raw("sum(profit_usd) as total_profit")
            )
            ->groupBy('report_date')
            ->orderByDesc('report_date')
            ->get();

        // 2. User-by-User Breakdown
        $userBreakdown = User::withCount(['generations as successful_generations' => function ($query) {
                $query->where('status', 'succeeded');
            }])
            ->withSum(['generations as total_spent' => function ($query) {
                $query->where('status', 'succeeded');
            }], 'user_charged_usd')
            ->withSum(['generations as total_provider_cost' => function ($query) {
                $query->where('status', 'succeeded');
            }], 'provider_cost_usd')
            ->withSum(['generations as total_profit' => function ($query) {
                $query->where('status', 'succeeded');
            }], 'profit_usd')
            ->get();

        // 3. Live reconciliation with Pixelforge GET /v1/usage
        $officialUsage = $client->getUsage();

        return Inertia::render('Admin/Report', [
            'dailyRecords' => $dailyRecords,
            'userBreakdown' => $userBreakdown,
            'officialUsage' => $officialUsage['data'] ?? null,
        ]);
    }
}
