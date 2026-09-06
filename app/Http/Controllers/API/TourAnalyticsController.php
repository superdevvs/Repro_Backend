<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TourEvent;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TourAnalyticsController extends Controller
{
    /**
     * Record a tour event from public tour pages (unauthenticated).
     * POST /api/public/tour-events
     */
    public function trackEvent(Request $request)
    {
        $validated = $request->validate([
            'shoot_id' => 'required|integer|exists:shoots,id',
            'event_type' => 'required|string|in:page_view,link_click,media_view,share,download',
            'tour_type' => 'nullable|string|in:branded,mls,generic_mls',
            'metadata' => 'nullable|array',
        ]);

        $ip = $request->ip();
        $ua = $request->userAgent() ?? '';
        $visitorId = hash('sha256', $ip . '|' . $ua);
        $referrer = $request->header('Referer') ?? $request->input('referrer');

        // Geo lookup (cached per IP for 24h)
        $geo = $this->resolveGeo($ip);

        TourEvent::create([
            'shoot_id' => $validated['shoot_id'],
            'event_type' => $validated['event_type'],
            'tour_type' => $validated['tour_type'] ?? null,
            'visitor_id' => $visitorId,
            'ip_address' => $ip,
            'user_agent' => mb_substr($ua, 0, 500),
            'referrer' => $referrer ? mb_substr($referrer, 0, 500) : null,
            'country' => $geo['country'] ?? null,
            'city' => $geo['city'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    /**
     * Get aggregated tour analytics for a shoot (authenticated).
     * GET /api/shoots/{shoot}/tour-analytics?range=week|month|year|all
     */
    public function summary(Request $request, Shoot $shoot)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $authorization = app(ShootAuthorizationSupport::class);
        if (! $authorization->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'client', 'salesRep'])
            || ! $authorization->canViewShootDetails($shoot, $user)
            || ($authorization->isClientUser($user) && (string) $shoot->client_id !== (string) $user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $range = $request->input('range', 'week');
        $now = Carbon::now();

        switch ($range) {
            case 'month':
                $startDate = $now->copy()->subDays(30);
                break;
            case 'year':
                $startDate = $now->copy()->subYear();
                break;
            case 'all':
                $startDate = null;
                break;
            default: // week
                $startDate = $now->copy()->subDays(7);
                break;
        }

        $query = TourEvent::where('shoot_id', $shoot->id);
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        $events = $query->get();

        // Total views
        $totalViews = $events->where('event_type', 'page_view')->count();
        $uniqueVisitors = $events->where('event_type', 'page_view')->pluck('visitor_id')->unique()->count();
        $totalClicks = $events->where('event_type', 'link_click')->count();
        $totalShares = $events->where('event_type', 'share')->count();
        $totalDownloads = $events->where('event_type', 'download')->count();
        $totalMediaViews = $events->where('event_type', 'media_view')->count();

        // Views by tour type
        $viewsByTourType = $events->where('event_type', 'page_view')
            ->groupBy('tour_type')
            ->map(fn($group) => $group->count())
            ->toArray();

        // Views over time (daily buckets)
        $viewsOverTime = $this->buildDailyBuckets($events->where('event_type', 'page_view'), $startDate, $now);

        // Top media viewed
        $topMedia = $events->where('event_type', 'media_view')
            ->groupBy(fn($e) => ($e->metadata['media_index'] ?? '') . ':' . ($e->metadata['media_filename'] ?? ''))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'media_index' => $first->metadata['media_index'] ?? null,
                    'media_filename' => $first->metadata['media_filename'] ?? null,
                    'media_url' => $first->metadata['media_url'] ?? null,
                    'views' => $group->count(),
                    'unique_viewers' => $group->pluck('visitor_id')->unique()->count(),
                ];
            })
            ->sortByDesc('views')
            ->values()
            ->take(10)
            ->toArray();

        // Referrer breakdown
        $referrers = $events->where('event_type', 'page_view')
            ->groupBy(function ($e) {
                $ref = $e->referrer;
                if (!$ref) return 'Direct';
                $host = parse_url($ref, PHP_URL_HOST) ?? $ref;
                $host = preg_replace('/^www\./', '', $host);
                return $host;
            })
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->toArray();

        // Device breakdown (parsed from user_agent)
        $devices = $events->where('event_type', 'page_view')
            ->groupBy(function ($e) {
                $ua = strtolower($e->user_agent ?? '');
                if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
                    return 'Mobile';
                }
                if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
                    return 'Tablet';
                }
                return 'Desktop';
            })
            ->map(fn($group) => $group->count())
            ->toArray();

        // Geographic breakdown
        $countries = $events->where('event_type', 'page_view')
            ->filter(fn($e) => !empty($e->country))
            ->groupBy('country')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->toArray();

        $cities = $events->where('event_type', 'page_view')
            ->filter(fn($e) => !empty($e->city))
            ->groupBy('city')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->toArray();

        // Clicks by tour type
        $clicksByTourType = $events->where('event_type', 'link_click')
            ->groupBy('tour_type')
            ->map(fn($group) => $group->count())
            ->toArray();

        return response()->json([
            'range' => $range,
            'summary' => [
                'total_views' => $totalViews,
                'unique_visitors' => $uniqueVisitors,
                'total_clicks' => $totalClicks,
                'total_shares' => $totalShares,
                'total_downloads' => $totalDownloads,
                'total_media_views' => $totalMediaViews,
            ],
            'views_by_tour_type' => $viewsByTourType,
            'views_over_time' => $viewsOverTime,
            'top_media' => $topMedia,
            'referrers' => $referrers,
            'devices' => $devices,
            'countries' => $countries,
            'cities' => $cities,
            'clicks_by_tour_type' => $clicksByTourType,
        ]);
    }

    /**
     * Build daily view buckets for the chart.
     */
    private function buildDailyBuckets($pageViews, ?Carbon $startDate, Carbon $endDate): array
    {
        $start = $startDate ? $startDate->copy()->startOfDay() : ($pageViews->min('created_at') ? Carbon::parse($pageViews->min('created_at'))->startOfDay() : $endDate->copy()->subDays(7)->startOfDay());
        $end = $endDate->copy()->endOfDay();
        $buckets = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $dateKey = $current->format('Y-m-d');
            $buckets[$dateKey] = [
                'date' => $dateKey,
                'views' => 0,
                'branded' => 0,
                'mls' => 0,
                'generic_mls' => 0,
            ];
            $current->addDay();
        }

        foreach ($pageViews as $event) {
            $dateKey = Carbon::parse($event->created_at)->format('Y-m-d');
            if (isset($buckets[$dateKey])) {
                $buckets[$dateKey]['views']++;
                $type = $event->tour_type ?? 'branded';
                if (isset($buckets[$dateKey][$type])) {
                    $buckets[$dateKey][$type]++;
                }
            }
        }

        return array_values($buckets);
    }

    /**
     * Resolve geo data from IP using ip-api.com (cached 24h per IP).
     */
    private function resolveGeo(string $ip): array
    {
        // Skip private/local IPs
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return ['country' => null, 'city' => null];
        }

        $cacheKey = 'geo_ip_' . md5($ip);
        return Cache::remember($cacheKey, 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=status,country,city");
                if ($response->successful()) {
                    $data = $response->json();
                    if (($data['status'] ?? '') === 'success') {
                        return [
                            'country' => $data['country'] ?? null,
                            'city' => $data['city'] ?? null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                \App\Services\ApiErrorResponder::log($e, 'debug');
            }
            return ['country' => null, 'city' => null];
        });
    }
}
