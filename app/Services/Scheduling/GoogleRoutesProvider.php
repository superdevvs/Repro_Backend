<?php

namespace App\Services\Scheduling;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleRoutesProvider
{
    private array $memo = [];

    public function __construct(private GoogleRoutesBudget $budget) {}

    public function reset(): void
    {
        $this->memo = [];
    }

    public function route(array $origin, array $destination, CarbonInterface $departure): array
    {
        $startedAt = hrtime(true);
        $result = ['status' => 'unavailable', 'reason_code' => 'routes_unavailable'];
        try {
            return $result = $this->resolveRoute($origin, $destination, $departure);
        } finally {
            Log::channel('scheduling')->info('routes_evaluation', [
                'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'status' => $result['status'], 'reason_code' => $result['reason_code'],
            ]);
        }
    }

    private function resolveRoute(array $origin, array $destination, CarbonInterface $departure): array
    {
        if (DB::transactionLevel() > 0) {
            return ['status' => 'unavailable', 'reason_code' => 'route_inside_transaction'];
        }
        if ($departure->isPast()) {
            return ['status' => 'unavailable', 'reason_code' => 'past_departure'];
        }
        $key = (string) config('services.google_routes.key', '');
        if ($key === '') {
            return ['status' => 'unavailable', 'reason_code' => 'routes_not_configured'];
        }
        $body = [
            'origins' => [['waypoint' => ['address' => $origin['full_address']]]],
            'destinations' => [['waypoint' => ['address' => $destination['full_address']]]],
            'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_AWARE',
            'departureTime' => $departure->copy()->utc()->toRfc3339String(),
        ];
        $memoKey = hash('sha256', json_encode($body));
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        try {
            if (! $this->budget->reserve()) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_budget_exhausted'];
            }
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'originIndex,destinationIndex,status,condition,duration,distanceMeters,fallbackInfo',
            ])->connectTimeout(1)->timeout(3)->post(config('services.google_routes.base_url'), $body);
            if (! $response->successful()) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_http_error'];
            }
            $data = $response->json();
            if (! is_array($data) || ! array_is_list($data)) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_invalid_response'];
            }
            // A bounded 1x1 matrix consumes one element. Select by indices, never
            // response order: streamed errors or unrelated elements are not proof
            // of a route for this leg. Protobuf may omit a zero-valued index.
            $matches = array_values(array_filter($data, fn ($element) => is_array($element)
                && ! isset($element['error']) && ($element['originIndex'] ?? 0) === 0
                && ($element['destinationIndex'] ?? 0) === 0 && isset($element['condition'])));
            if (count($matches) !== 1) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_missing_element'];
            }
            $route = $matches[0];
            // Condition is independent of element status; a definitive no-route
            // must never become an optimistic straight-line mileage estimate.
            if ($route['condition'] === 'ROUTE_NOT_FOUND') {
                return $this->memo[$memoKey] = ['status' => 'no_route', 'reason_code' => 'route_not_found'];
            }
            if (($route['status']['code'] ?? 0) !== 0 || $route['condition'] !== 'ROUTE_EXISTS') {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_element_error'];
            }
            if (! empty($route['fallbackInfo'])) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_traffic_unavailable'];
            }
            if (! preg_match('/^(\d+(?:\.\d+)?)s$/', (string) ($route['duration'] ?? ''), $match)
                || ! is_finite((float) $match[1]) || ! is_numeric($route['distanceMeters'] ?? null)
                || ! is_finite((float) $route['distanceMeters']) || (float) $route['distanceMeters'] < 0) {
                return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_invalid_response'];
            }

            return $this->memo[$memoKey] = ['status' => 'ok', 'duration_seconds' => (float) $match[1],
                'distance_meters' => (float) $route['distanceMeters'], 'reason_code' => 'traffic_aware_route'];
        } catch (Throwable $exception) {
            Log::channel('scheduling')->notice('routes_unavailable', ['exception_type' => $exception::class]);

            return $this->memo[$memoKey] = ['status' => 'unavailable', 'reason_code' => 'routes_unavailable'];
        }
    }
}
