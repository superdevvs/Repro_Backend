<?php

namespace App\Services;

use App\Events\SystemOverviewActivityUpdated;
use App\Models\SystemOverviewErrorEvent;
use App\Models\SystemOverviewRequestTrace;
use App\Models\SystemOverviewRouteEvent;
use App\Models\SystemOverviewSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SystemOverviewTelemetryService
{
    private const REQUIRED_TABLES = [
        'system_overview_sessions',
        'system_overview_route_events',
        'system_overview_request_traces',
        'system_overview_error_events',
    ];

    private const LIVE_WINDOW_SECONDS = 120;
    private const HISTORY_HOURS = 24;
    private const IGNORED_PREFIXES = [
        'api/admin/system-overview',
        'api/system-telemetry',
        'api/broadcasting/auth',
        'api/profile/tax-document',
    ];

    private const EVENT_TYPES = [
        'session_start',
        'session_end',
        'route_enter',
        'route_leave',
        'heartbeat',
        'component_mount',
        'component_unmount',
        'action',
        'blocker',
        'error',
    ];

    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'auth',
        'secret',
        'api_key',
        'apikey',
        'ssn',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'email_body',
        'html',
        'body',
        'raw',
        'message',
        'content',
        'notes',
        'company_notes',
        'editor_notes',
        'photographer_notes',
    ];

    private ?bool $telemetryAvailableCache = null;

    public function telemetryAvailable(): bool
    {
        if ($this->telemetryAvailableCache !== null) {
            return $this->telemetryAvailableCache;
        }

        try {
            foreach (self::REQUIRED_TABLES as $table) {
                if (!Schema::hasTable($table)) {
                    return $this->telemetryAvailableCache = false;
                }
            }

            return $this->telemetryAvailableCache = true;
        } catch (Throwable) {
            return $this->telemetryAvailableCache = false;
        }
    }

    public function recordRequestTrace(Request $request, Response $response): ?SystemOverviewRequestTrace
    {
        if (!$this->telemetryAvailable() || !$this->shouldTrackRequest($request)) {
            return null;
        }

        $traceId = $this->resolveTraceId($request);
        $startedAt = (float) ($request->attributes->get('system_overview.started_at') ?? microtime(true));
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $session = $this->upsertSessionFromRequest($request);
        $blocker = $this->classifyBlocker($request, $response->getStatusCode(), $durationMs);

        $trace = SystemOverviewRequestTrace::query()->updateOrCreate(
            ['trace_id' => $traceId],
            [
                'system_overview_session_id' => $session?->id,
                'session_key' => $session?->session_key,
                'user_id' => $request->user()?->id,
                'domain' => $this->resolveDomain($request),
                'route_name' => optional($request->route())->getName(),
                'method' => strtoupper($request->method()),
                'path' => $this->routeTemplate($request),
                'current_route' => $this->safeLabel($request->headers->get('X-System-Current-Route')),
                'controller_action' => $this->resolveControllerAction($request->route()),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'request_bytes' => strlen((string) $request->getContent()),
                'response_bytes' => strlen((string) $response->getContent()),
                'blocker_type' => $blocker['type'],
                'blocker_message' => $blocker['message'],
                'error_class' => null,
                'request_payload_summary' => $this->requestSummary($request),
                'response_payload_summary' => $this->responseSummary($response),
                'metadata' => [
                    'ip' => $request->ip(),
                    'route_parameter_names' => array_keys($request->route()?->parameters() ?? []),
                    'client_correlation_id' => $request->attributes->get('api.client_correlation_id'),
                ],
                'occurred_at' => now(),
            ],
        );

        $this->touchSession($session, [
            'last_api_path' => $trace->path,
            'last_trace_id' => $trace->trace_id,
            'blocker_state' => $blocker['state'],
            'blocker_message' => $blocker['message'],
        ]);

        $this->broadcast('trace', [
            'traceId' => $trace->trace_id,
            'domain' => $trace->domain,
            'path' => $trace->path,
            'statusCode' => $trace->status_code,
            'durationMs' => $trace->duration_ms,
            'userName' => $session?->user_name,
            'currentRoute' => $session?->current_route,
        ]);

        return $trace;
    }

    public function recordException(Request $request, Throwable $exception, int $status): void
    {
        if (!$this->telemetryAvailable() || !$this->shouldTrackRequest($request)) {
            return;
        }

        $traceId = $this->resolveTraceId($request);
        $startedAt = (float) ($request->attributes->get('system_overview.started_at') ?? microtime(true));
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $session = $this->upsertSessionFromRequest($request);

        SystemOverviewRequestTrace::query()->updateOrCreate(
            ['trace_id' => $traceId],
            [
                'system_overview_session_id' => $session?->id,
                'session_key' => $session?->session_key,
                'user_id' => $request->user()?->id,
                'domain' => $this->resolveDomain($request),
                'route_name' => optional($request->route())->getName(),
                'method' => strtoupper($request->method()),
                'path' => $this->routeTemplate($request),
                'current_route' => $this->safeLabel($request->headers->get('X-System-Current-Route')),
                'controller_action' => $this->resolveControllerAction($request->route()),
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'request_bytes' => strlen((string) $request->getContent()),
                'response_bytes' => 0,
                'blocker_type' => 'error',
                'blocker_message' => ApiErrorResponder::defaultMessage($status),
                'error_class' => $exception::class,
                'request_payload_summary' => $this->requestSummary($request),
                'response_payload_summary' => null,
                'metadata' => [
                    'ip' => $request->ip(),
                    'route_parameter_names' => array_keys($request->route()?->parameters() ?? []),
                    'client_correlation_id' => $request->attributes->get('api.client_correlation_id'),
                ],
                'occurred_at' => now(),
            ],
        );

        SystemOverviewErrorEvent::query()->create([
            'system_overview_session_id' => $session?->id,
            'session_key' => $session?->session_key,
            'user_id' => $request->user()?->id,
            'trace_id' => $traceId,
            'source' => 'backend',
            'severity' => $status >= 500 ? 'critical' : 'warning',
            'route_path' => $this->routeTemplate($request),
            'component_name' => null,
            'blocker_type' => 'error',
            'error_class' => $exception::class,
            'message' => ApiErrorResponder::defaultMessage($status),
            'context_summary' => [
                'line' => ApiErrorResponder::diagnosticContext($exception)['line'],
                'file' => ApiErrorResponder::diagnosticContext($exception)['file'],
                'trace_id' => $traceId,
            ],
            'occurred_at' => now(),
        ]);

        $this->touchSession($session, [
            'last_api_path' => $this->routeTemplate($request),
            'last_trace_id' => $traceId,
            'blocker_state' => 'error',
            'blocker_message' => ApiErrorResponder::defaultMessage($status),
        ]);

        $this->broadcast('error', [
            'traceId' => $traceId,
            'path' => $this->routeTemplate($request),
            'statusCode' => $status,
            'message' => ApiErrorResponder::defaultMessage($status),
            'userName' => $session?->user_name,
            'currentRoute' => $session?->current_route,
        ]);
    }

    public function recordClientEvent(Request $request, array $event): ?array
    {
        if (!$this->telemetryAvailable()) {
            return null;
        }

        $type = (string) ($event['type'] ?? '');
        if (!in_array($type, self::EVENT_TYPES, true)) {
            return null;
        }

        $occurredAt = isset($event['occurredAt']) ? Carbon::parse($event['occurredAt']) : now();
        // A browser may correlate only to a server-issued trace owned by this user.
        $requestedTrace = $event['traceId'] ?? null;
        $event['traceId'] = is_string($requestedTrace) && Str::isUuid($requestedTrace)
            && $request->user() && SystemOverviewRequestTrace::query()
                ->where('trace_id', $requestedTrace)->where('user_id', $request->user()->id)->exists()
            ? $requestedTrace : $this->resolveTraceId($request);
        $event['message'] = in_array($type, ['error', 'blocker'], true) ? 'A browser operation could not be completed.' : null;
        $event['blockerMessage'] = $event['message'];
        $event['errorClass'] = in_array($type, ['error', 'blocker'], true) ? 'ClientOperationError' : null;
        foreach (['routePath', 'pageKey', 'componentName', 'actionName', 'severity', 'blockerState', 'blockerType'] as $field) {
            $event[$field] = $this->safeLabel($event[$field] ?? null);
        }
        $session = $this->upsertSessionFromRequest($request, [
            'current_route' => $event['routePath'] ?? $request->headers->get('X-System-Current-Route'),
            'current_page' => $event['pageKey'] ?? null,
            'current_action' => $event['actionName'] ?? $type,
            'metadata' => [
                'user_agent' => $request->userAgent(),
            ],
        ]);
        $payloadSummary = $this->summarizePayload($event['payload'] ?? Arr::except($event, ['type']));

        SystemOverviewRouteEvent::query()->create([
            'system_overview_session_id' => $session?->id,
            'session_key' => $session?->session_key,
            'user_id' => $request->user()?->id,
            'event_type' => $type,
            'route_path' => $event['routePath'] ?? null,
            'page_key' => $event['pageKey'] ?? null,
            'component_name' => $event['componentName'] ?? null,
            'action_name' => $event['actionName'] ?? null,
            'severity' => $event['severity'] ?? null,
            'blocker_state' => $event['blockerState'] ?? null,
            'payload_summary' => $payloadSummary,
            'occurred_at' => $occurredAt,
        ]);

        $this->updateSessionFromEvent($session, $type, $event, $occurredAt);

        if (in_array($type, ['error', 'blocker'], true)) {
            SystemOverviewErrorEvent::query()->create([
                'system_overview_session_id' => $session?->id,
                'session_key' => $session?->session_key,
                'user_id' => $request->user()?->id,
                'trace_id' => $event['traceId'],
                'source' => 'frontend',
                'severity' => $event['severity'] ?? 'warning',
                'route_path' => $event['routePath'] ?? null,
                'component_name' => $event['componentName'] ?? null,
                'blocker_type' => $event['blockerType'] ?? ($type === 'error' ? 'error' : 'blocker'),
                'error_class' => $event['errorClass'] ?? null,
                'message' => (string) ($event['message'] ?? $event['blockerMessage'] ?? 'Frontend issue'),
                'context_summary' => $payloadSummary,
                'occurred_at' => $occurredAt,
            ]);
        }

        $delta = [
            'type' => $type,
            'userName' => $session?->user_name,
            'userRole' => $session?->user_role,
            'routePath' => $event['routePath'] ?? null,
            'componentName' => $event['componentName'] ?? null,
            'actionName' => $event['actionName'] ?? null,
            'traceId' => $event['traceId'],
            'blockerState' => $event['blockerState'] ?? null,
            'message' => $event['message'] ?? null,
            'occurredAt' => $occurredAt->toIso8601String(),
        ];

        $this->broadcast('activity', $delta);

        return $delta;
    }

    public function buildSnapshot(): array
    {
        if (!$this->telemetryAvailable()) {
            return $this->emptySnapshot();
        }

        $cutoff = now()->subHours(self::HISTORY_HOURS);
        $liveUsers = $this->buildLiveUsers();
        $traces = SystemOverviewRequestTrace::query()
            ->where('occurred_at', '>=', $cutoff)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get();
        $errors = SystemOverviewErrorEvent::query()
            ->where('occurred_at', '>=', $cutoff)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get();

        $routeMetrics = $traces
            ->groupBy(fn (SystemOverviewRequestTrace $trace) => $trace->path)
            ->map(function (Collection $items, string $path) {
                return [
                    'path' => $path,
                    'domain' => $items->first()?->domain,
                    'requestCount' => $items->count(),
                    'errorCount' => $items->filter(fn (SystemOverviewRequestTrace $trace) => (int) $trace->status_code >= 400)->count(),
                    'avgDurationMs' => (int) round($items->avg('duration_ms') ?? 0),
                    'maxDurationMs' => (int) ($items->max('duration_ms') ?? 0),
                    'lastStatusCode' => $items->first()?->status_code,
                    'lastSeenAt' => optional($items->first()?->occurred_at)->toIso8601String(),
                ];
            })
            ->sortByDesc('requestCount')
            ->values()
            ->all();

        $domainStats = collect($this->allDomains())
            ->mapWithKeys(function (string $domain) use ($traces, $errors, $liveUsers) {
                $domainTraces = $traces->where('domain', $domain);
                $domainErrors = $errors->filter(fn (SystemOverviewErrorEvent $error) => $this->resolveDomainFromPath($error->route_path) === $domain);
                $domainUsers = collect($liveUsers)->filter(fn (array $user) => $this->resolveDomainFromPath($user['currentRoute'] ?? null) === $domain);

                return [
                    $domain => [
                        'activeUsers' => $domainUsers->count(),
                        'requests' => $domainTraces->count(),
                        'errors' => $domainErrors->count(),
                        'avgDurationMs' => (int) round($domainTraces->avg('duration_ms') ?? 0),
                    ],
                ];
            })
            ->all();

        return [
            'generatedAt' => now()->toIso8601String(),
            'stats' => [
                'activeSessions' => count($liveUsers),
                'requestsPerMinute' => SystemOverviewRequestTrace::query()->where('occurred_at', '>=', now()->subMinute())->count(),
                'errorCount24h' => SystemOverviewErrorEvent::query()->where('occurred_at', '>=', $cutoff)->count(),
                'slowRouteCount' => SystemOverviewRequestTrace::query()->where('occurred_at', '>=', $cutoff)->where('duration_ms', '>=', 1500)->count(),
                'integrationFailures24h' => SystemOverviewRequestTrace::query()->where('occurred_at', '>=', $cutoff)->where('domain', 'Integrations')->whereNotNull('blocker_type')->count(),
            ],
            'domainStats' => $domainStats,
            'liveUsers' => $liveUsers,
            'routeMetrics' => $routeMetrics,
            'recentTraces' => $traces->map(fn (SystemOverviewRequestTrace $trace) => $this->formatTrace($trace))->values()->all(),
            'recentErrors' => $errors->map(fn (SystemOverviewErrorEvent $error) => $this->formatError($error))->values()->all(),
        ];
    }

    public function buildHistory(): array
    {
        if (!$this->telemetryAvailable()) {
            return [
                'window' => '24h',
                'timeline' => [],
            ];
        }

        $cutoff = now()->subHours(self::HISTORY_HOURS);
        $traces = SystemOverviewRequestTrace::query()->where('occurred_at', '>=', $cutoff)->orderBy('occurred_at')->get();
        $errors = SystemOverviewErrorEvent::query()->where('occurred_at', '>=', $cutoff)->orderBy('occurred_at')->get();
        $sessions = SystemOverviewSession::query()->where('last_activity_at', '>=', $cutoff)->get();

        $timeline = collect();
        $cursor = $cutoff->copy()->startOfMinute();
        $end = now()->startOfMinute();

        while ($cursor->lte($end)) {
            $bucketStart = $cursor->copy();
            $bucketEnd = $cursor->copy()->addMinutes(15);

            $bucketTraces = $traces->filter(fn (SystemOverviewRequestTrace $trace) => $trace->occurred_at && $trace->occurred_at->between($bucketStart, $bucketEnd, true));
            $bucketErrors = $errors->filter(fn (SystemOverviewErrorEvent $error) => $error->occurred_at && $error->occurred_at->between($bucketStart, $bucketEnd, true));
            $bucketSessions = $sessions->filter(fn (SystemOverviewSession $session) => $session->last_activity_at && $session->last_activity_at->between($bucketStart, $bucketEnd, true));

            $timeline->push([
                'bucketStart' => $bucketStart->toIso8601String(),
                'bucketEnd' => $bucketEnd->toIso8601String(),
                'requests' => $bucketTraces->count(),
                'errors' => $bucketErrors->count(),
                'activeSessions' => $bucketSessions->count(),
                'avgDurationMs' => (int) round($bucketTraces->avg('duration_ms') ?? 0),
            ]);

            $cursor->addMinutes(15);
        }

        return [
            'window' => '24h',
            'timeline' => $timeline->all(),
        ];
    }

    public function buildLiveUsers(): array
    {
        if (!$this->telemetryAvailable()) {
            return [];
        }

        return SystemOverviewSession::query()
            ->where('is_active', true)
            ->where('last_activity_at', '>=', now()->subSeconds(self::LIVE_WINDOW_SECONDS))
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(function (SystemOverviewSession $session) {
                return [
                    'sessionKey' => $session->session_key,
                    'userId' => $session->user_id,
                    'userName' => $session->user_name,
                    'userRole' => $session->user_role,
                    'currentRoute' => $session->current_route,
                    'currentPage' => $session->current_page,
                    'currentAction' => $session->current_action,
                    'componentStack' => $session->component_stack ?? [],
                    'blockerState' => $session->blocker_state,
                    'blockerMessage' => $session->blocker_message ? 'An operation needs attention. Use its request ID for support.' : null,
                    'lastApiPath' => $session->last_api_path,
                    'lastTraceId' => $session->last_trace_id,
                    'lastActivityAt' => optional($session->last_activity_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    public function buildRoutesCatalog(): array
    {
        if (!$this->telemetryAvailable()) {
            return [];
        }

        $metrics = collect($this->buildSnapshot()['routeMetrics'])->keyBy('path');

        return collect(RouteFacade::getRoutes())
            ->map(function (Route $route) use ($metrics) {
                $path = '/'.$route->uri();

                return [
                    'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                    'path' => $path,
                    'name' => $route->getName(),
                    'domain' => $this->resolveDomainFromPath($path),
                    'controllerAction' => $this->resolveControllerAction($route),
                    'middleware' => $route->gatherMiddleware(),
                    'metrics' => $metrics->get($path),
                ];
            })
            ->filter(fn (array $route) => Str::startsWith($route['path'], '/api/'))
            ->values()
            ->all();
    }

    public function buildTraceDetail(string $traceId): ?array
    {
        if (!$this->telemetryAvailable()) {
            return null;
        }

        $trace = SystemOverviewRequestTrace::query()->where('trace_id', $traceId)->first();
        if (!$trace) {
            return null;
        }

        $session = $trace->session;

        return [
            'trace' => $this->formatTrace($trace),
            'session' => $session ? [
                'sessionKey' => $session->session_key,
                'userName' => $session->user_name,
                'userRole' => $session->user_role,
                'currentRoute' => $session->current_route,
                'currentAction' => $session->current_action,
                'componentStack' => $session->component_stack ?? [],
                'lastActivityAt' => optional($session->last_activity_at)->toIso8601String(),
            ] : null,
            'errors' => SystemOverviewErrorEvent::query()
                ->where('trace_id', $traceId)
                ->orderByDesc('occurred_at')
                ->get()
                ->map(fn (SystemOverviewErrorEvent $error) => $this->formatError($error))
                ->values()
                ->all(),
            'recentEvents' => $session
                ? $session->routeEvents()
                    ->orderByDesc('occurred_at')
                    ->limit(20)
                    ->get()
                    ->map(fn (SystemOverviewRouteEvent $event) => [
                        'type' => $event->event_type,
                        'routePath' => $event->route_path,
                        'pageKey' => $event->page_key,
                        'componentName' => $event->component_name,
                        'actionName' => $event->action_name,
                        'severity' => $event->severity,
                        'payloadSummary' => $this->summarizePayload($event->payload_summary),
                        'occurredAt' => optional($event->occurred_at)->toIso8601String(),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    public function prune(): array
    {
        if (!$this->telemetryAvailable()) {
            return [
                'sessions' => 0,
                'route_events' => 0,
                'request_traces' => 0,
                'error_events' => 0,
            ];
        }

        $cutoff = now()->subHours(self::HISTORY_HOURS);

        return [
            'sessions' => SystemOverviewSession::query()->where('last_activity_at', '<', $cutoff)->delete(),
            'route_events' => SystemOverviewRouteEvent::query()->where('occurred_at', '<', $cutoff)->delete(),
            'request_traces' => SystemOverviewRequestTrace::query()->where('occurred_at', '<', $cutoff)->delete(),
            'error_events' => SystemOverviewErrorEvent::query()->where('occurred_at', '<', $cutoff)->delete(),
        ];
    }

    private function shouldTrackRequest(Request $request): bool
    {
        if (!$request->is('api/*') || $request->is('api/admin/users/*/tax-document*')) {
            return false;
        }

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if ($request->is($prefix.'*')) {
                return false;
            }
        }

        return $request->user() !== null;
    }

    private function upsertSessionFromRequest(Request $request, array $overrides = []): ?SystemOverviewSession
    {
        $sessionKey = $request->headers->get('X-System-Session-Id');
        $user = $request->user();

        if (!is_string($sessionKey) || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $sessionKey) || !$user) {
            return null;
        }

        // Client session identifiers are scoped to their authenticated owner.
        $sessionKey = hash('sha256', $user->id.':'.$sessionKey);

        $session = SystemOverviewSession::query()->firstOrNew([
            'session_key' => $sessionKey,
        ]);

        $session->fill([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_role' => $user->role,
            'is_authenticated' => true,
            'is_active' => true,
            'current_route' => $this->safeLabel($overrides['current_route'] ?? $request->headers->get('X-System-Current-Route')),
            'current_page' => $this->safeLabel($overrides['current_page'] ?? $session->current_page),
            'current_action' => $this->safeLabel($overrides['current_action'] ?? $session->current_action ?? 'request'),
            'metadata' => ['source' => 'browser'],
            'last_activity_at' => now(),
        ]);

        if (!$session->exists) {
            $session->started_at = now();
        }

        $session->save();

        return $session;
    }

    private function touchSession(?SystemOverviewSession $session, array $attributes = []): void
    {
        if (!$session) {
            return;
        }

        foreach ($attributes as $key => $value) {
            if ($value !== null) {
                $session->{$key} = $value;
            }
        }
        $session->last_activity_at = now();
        $session->save();
    }

    private function updateSessionFromEvent(?SystemOverviewSession $session, string $type, array $event, Carbon $occurredAt): void
    {
        if (!$session) {
            return;
        }

        $components = collect($session->component_stack ?? []);
        if ($type === 'component_mount' && !empty($event['componentName'])) {
            $components->push($event['componentName']);
        }
        if ($type === 'component_unmount' && !empty($event['componentName'])) {
            $components = $components->reject(fn (string $name) => $name === $event['componentName']);
        }

        $session->current_route = $event['routePath'] ?? $session->current_route;
        $session->current_page = $event['pageKey'] ?? $session->current_page;
        $session->current_action = $event['actionName'] ?? $type;
        $session->component_stack = $components->filter()->unique()->values()->take(20)->all();
        $session->blocker_state = $event['blockerState'] ?? $session->blocker_state;
        $session->blocker_message = $event['blockerMessage'] ?? $event['message'] ?? $session->blocker_message;
        $session->last_activity_at = $occurredAt;

        if ($type === 'session_end') {
            $session->is_active = false;
            $session->ended_at = $occurredAt;
        }

        $session->save();
    }

    private function requestSummary(Request $request): ?array
    {
        if ($request->isMethod('GET')) {
            return $this->summarizePayload($request->query());
        }

        $payload = $request->all();
        if ($payload === []) {
            $decoded = json_decode((string) $request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return $this->summarizePayload($payload);
    }

    private function responseSummary(Response $response): ?array
    {
        if (!$response instanceof JsonResponse) {
            return [
                'byteSize' => strlen((string) $response->getContent()),
                'contentType' => $response->headers->get('content-type'),
                'topLevelKeys' => [],
                'preview' => null,
            ];
        }

        return $this->summarizePayload($response->getData(true));
    }

    private function summarizePayload(mixed $payload): ?array
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $sanitized = $this->sanitizePayload($payload);
        $json = json_encode($sanitized);

        return [
            'topLevelKeys' => is_array($sanitized) ? array_slice(array_keys($sanitized), 0, 12) : [],
            'keyCount' => is_array($sanitized) ? count($sanitized) : 0,
            'byteSize' => $json ? strlen($json) : 0,
            'preview' => $this->previewPayload($sanitized),
            'sanitized' => $sanitized,
        ];
    }

    private function sanitizePayload(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 3) {
            return is_array($value) ? '[TRUNCATED]' : $this->sanitizeScalar($value);
        }

        if (!is_array($value)) {
            return $this->sanitizeScalar($value);
        }

        $result = [];

        foreach (array_slice($value, 0, 40, true) as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            // Payload field names can themselves contain user data. Only retain
            // reviewed envelope/schema names; all values are represented by type.
            $key = is_int($key) ? $key : (in_array($normalizedKey, [
                'data', 'errors', 'message', 'code', 'status', 'success', 'request_id',
                'items', 'id', 'type', 'name', 'email', 'role', 'metadata', 'file',
                'files', 'upload', 'upload_session', 'error_type', 'total', 'page',
                ...self::REDACTED_KEYS,
            ], true) ? $normalizedKey : 'field_'.count($result));
            if (in_array($normalizedKey, self::REDACTED_KEYS, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            $result[$key] = $this->sanitizePayload($item, $depth + 1);
        }

        return $result;
    }

    private function sanitizeScalar(mixed $value): mixed
    {
        if (is_string($value)) {
            return '[STRING]';
        }

        return match (true) {
            is_int($value), is_float($value) => '[NUMBER]',
            is_bool($value) => '[BOOLEAN]',
            $value === null => null,
            default => '[OBJECT]',
        };
    }

    private function previewPayload(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return is_scalar($payload) ? Str::limit((string) $payload, 120) : null;
        }

        return collect($payload)
            ->take(3)
            ->map(function ($value, $key) {
                if (is_array($value)) {
                    return $key.': [...]';
                }

                return $key.': '.Str::limit((string) $value, 40);
            })
            ->implode(', ');
    }

    private function classifyBlocker(Request $request, int $statusCode, int $durationMs): array
    {
        $domain = $this->resolveDomain($request);

        if ($statusCode >= 500) {
            return [
                'type' => $domain === 'Integrations' ? 'integration_failure' : 'error',
                'state' => 'error',
                'message' => 'Server error',
            ];
        }

        if ($statusCode >= 400) {
            return [
                'type' => 'client_error',
                'state' => 'warning',
                'message' => 'Request failed',
            ];
        }

        if ($durationMs >= 2000) {
            return [
                'type' => 'slow_request',
                'state' => 'warning',
                'message' => 'Slow response',
            ];
        }

        return [
            'type' => null,
            'state' => null,
            'message' => null,
        ];
    }

    private function resolveDomain(Request $request): string
    {
        return $this->resolveDomainFromPath('/'.$request->path(), $this->resolveControllerAction($request->route()));
    }

    private function resolveDomainFromPath(?string $path, ?string $controllerAction = null): string
    {
        $value = strtolower((string) ($path ?? ''));
        $action = strtolower((string) ($controllerAction ?? ''));

        return match (true) {
            Str::contains($value, ['login', 'register', 'password', 'auth']) || Str::contains($action, 'auth') => 'Auth',
            Str::contains($value, ['dashboard']) => 'Dashboard',
            Str::contains($value, ['shoot', 'book-shoot', 'availability']) => 'Shoots',
            Str::contains($value, ['account', 'client', 'photographer', 'admin/users']) => 'Accounts',
            Str::contains($value, ['message', 'sms', 'email', 'automation', 'contact-submissions']) => 'Messaging',
            Str::contains($value, ['invoice', 'payment', 'billing', 'accounting']) => 'Billing',
            Str::contains($value, ['integration', 'dropbox', 'stripe', 'square', 'telnyx', 'cakemail', 'mls', 'iguide', 'mmm']) => 'Integrations',
            Str::contains($value, ['ai', 'robbie', 'cubicasa', 'higgs']) => 'AI',
            Str::contains($value, ['setting', 'permission']) => 'Settings',
            default => 'System',
        };
    }

    private function resolveControllerAction(?Route $route): ?string
    {
        $action = $route?->getActionName();

        return is_string($action) && $action !== 'Closure' ? $action : null;
    }

    private function resolveTraceId(Request $request): string
    {
        return RequestCorrelation::id($request);
    }

    private function routeTemplate(Request $request): string
    {
        $route = $request->route();
        return $route instanceof Route ? '/'.ltrim($route->uri(), '/') : '/api/unmatched';
    }

    private function safeLabel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            $value = preg_split('/[?#]/', $value, 2)[0];
            $first = explode('/', trim($value, '/'))[0] ?? '';
            // Browser routes are page locations, not arbitrary URLs/token paths.
            $value = in_array($first, ['', 'dashboard', 'shoots', 'accounts', 'accounting', 'messaging', 'settings', 'reset-password', 'calendar', 'scheduling', 'studio', 'inbox', 'book-shoot', 'shoot-history', 'availability', 'calls', 'invoices', 'integrations', 'mls-publishing-queue', 'chat-with-reproai', 'ai-editing', 'cubicasa-scanning', 'permission-settings', 'scheduling-settings'], true)
                ? ($first === '' ? '/' : '/'.$first) : '/unmatched';
        } elseif (preg_match('/\A(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+\//', $value, $match)) {
            $value = $match[1].' API request';
        }
        // No URL query strings, control characters, or free-form diagnostic text.
        return preg_match('/\A[A-Za-z0-9_\/. :{}-]{1,160}\z/', $value) ? $value : null;
    }

    private function formatTrace(SystemOverviewRequestTrace $trace): array
    {
        return [
            'traceId' => $trace->trace_id,
            'sessionKey' => $trace->session_key,
            'userId' => $trace->user_id,
            'domain' => $trace->domain,
            'routeName' => $trace->route_name,
            'method' => $trace->method,
            'path' => $trace->path,
            'currentRoute' => $trace->current_route,
            'controllerAction' => $trace->controller_action,
            'statusCode' => $trace->status_code,
            'durationMs' => $trace->duration_ms,
            'requestBytes' => $trace->request_bytes,
            'responseBytes' => $trace->response_bytes,
            'blockerType' => $trace->blocker_type,
            'blockerMessage' => $trace->blocker_message ? ApiErrorResponder::defaultMessage((int) $trace->status_code) : null,
            'errorClass' => $trace->error_class,
            'requestPayloadSummary' => $this->summarizePayload($trace->request_payload_summary),
            'responsePayloadSummary' => $this->summarizePayload($trace->response_payload_summary),
            'occurredAt' => optional($trace->occurred_at)->toIso8601String(),
        ];
    }

    private function formatError(SystemOverviewErrorEvent $error): array
    {
        return [
            'sessionKey' => $error->session_key,
            'userId' => $error->user_id,
            'traceId' => $error->trace_id,
            'source' => $error->source,
            'severity' => $error->severity,
            'routePath' => $error->route_path,
            'componentName' => $error->component_name,
            'blockerType' => $error->blocker_type,
            'errorClass' => $error->error_class,
            'message' => 'An operation could not be completed. Use the request ID for support.',
            'contextSummary' => $this->summarizePayload($error->context_summary),
            'occurredAt' => optional($error->occurred_at)->toIso8601String(),
        ];
    }

    private function allDomains(): array
    {
        return ['Auth', 'Dashboard', 'Shoots', 'Accounts', 'Messaging', 'Billing', 'Integrations', 'AI', 'Settings', 'System'];
    }

    private function emptySnapshot(): array
    {
        return [
            'generatedAt' => now()->toIso8601String(),
            'stats' => [
                'activeSessions' => 0,
                'requestsPerMinute' => 0,
                'errorCount24h' => 0,
                'slowRouteCount' => 0,
                'integrationFailures24h' => 0,
            ],
            'domainStats' => collect($this->allDomains())
                ->mapWithKeys(fn (string $domain) => [
                    $domain => [
                        'activeUsers' => 0,
                        'requests' => 0,
                        'errors' => 0,
                        'avgDurationMs' => 0,
                    ],
                ])
                ->all(),
            'liveUsers' => [],
            'routeMetrics' => [],
            'recentTraces' => [],
            'recentErrors' => [],
        ];
    }

    private function broadcast(string $kind, array $payload): void
    {
        event(new SystemOverviewActivityUpdated($kind, $payload));
    }
}
