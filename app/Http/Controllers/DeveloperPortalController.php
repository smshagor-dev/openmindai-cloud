<?php

namespace App\Http\Controllers;

use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeveloperPortalController
{
    public function catalog(Request $request, HostedCodeAgents $agents)
    {
        // Login sessions may inspect model availability but cannot invoke models.
        return response()->json(['object' => 'list', 'data' => $agents->publicDeveloperCatalog()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function usage(Request $request, ApiCreditUsage $usage)
    {
        $user = $request->user();
        $monthStart = $usage->monthStart();
        $used = (int) DB::table('api_credit_usage')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $monthStart)
            ->sum('credits');
        $limit = $user->monthly_credit_limit === null ? null : (int) $user->monthly_credit_limit;

        // Keep account-wide credit limits, while displaying coding usage only.
        $codingUsed = (int) DB::table('api_credit_usage as usage')
            ->join('api_model_connections as models', 'usage.connection_id', '=', 'models.id')
            ->where('usage.user_id', $user->id)
            ->where('usage.created_at', '>=', $monthStart)
            ->where('models.kind', 'code')
            ->sum('usage.credits');
        $models = $codingUsed > 0 ? [[
            'model' => 'OpenMindAI',
            'kind' => 'Coding agent',
            'used' => $codingUsed,
        ]] : [];

        $daily = DB::table('api_credit_usage')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(credits) as used')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => $row->day, 'used' => (int) $row->used]);

        return response()->json([
            'month' => now()->format('Y-m'),
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'by_model' => $models,
            'daily' => $daily,
            'unit' => 'completed_request',
            'note' => 'One completed API request is one application credit, not a provider token or billing balance.',
        ])->header('Cache-Control', 'private, no-store');
    }
}
