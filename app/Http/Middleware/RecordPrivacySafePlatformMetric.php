<?php

namespace App\Http\Middleware;

use App\Models\PlatformDailyMetric;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RecordPrivacySafePlatformMetric
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $this->shouldCount($request, $response) || ! Schema::hasTable('platform_daily_metrics')) {
            return $response;
        }

        $channel = $request->is('api/*') ? 'app' : 'web';
        $date = now()->toDateString();
        $inserted = DB::table('platform_daily_metrics')->insertOrIgnore([[
            'metric_date' => $date,
            'channel' => $channel,
            'page_views' => 1,
            'requests' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        if ($inserted === 0) {
            DB::table('platform_daily_metrics')
                ->where('metric_date', $date)->where('channel', $channel)
                ->incrementEach(['page_views' => 1, 'requests' => 1], ['updated_at' => now()]);
        }

        return $response;
    }

    private function shouldCount(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() >= 400) return false;
        if ($request->is('admin/*', 'vendor/*', 'storage/*', 'assets/*')) return false;
        if ($request->is('api/*')) return $request->is('api/v1/*');
        return ! $request->expectsJson();
    }
}
