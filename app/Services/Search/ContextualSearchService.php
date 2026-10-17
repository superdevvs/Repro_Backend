<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ContextualSearchService
{
    private const LOCAL_SCOPES = ['availability' => ['Availability', 'availability'], 'permissions' => ['Permissions', 'permissions-manager'],
        'settings' => ['Settings', 'settings'], 'reports' => ['Reports', 'reports'], 'profile' => ['Profile', 'profile'],
        'integrations' => ['Integrations', 'integrations'], 'booking' => ['Book a new shoot', 'book-shoot'], 'chat-history' => ['My AI chat history', 'robbie']];
    // Destinations and filters are server-owned; callers cannot proxy arbitrary APIs.
    private const SOURCES = [
        'shoots' => ['Shoots', '/shoots', 'shoots', 'search', ['tab' => 'all']],
        'listings' => ['Exclusive listings', '/shoots', 'portal', 'search', ['tab' => 'delivered', 'private_listing' => '1']],
        'invoices' => ['Invoices', '/invoices', 'accounting', 'search', []],
        'email' => ['Email', '/messaging/email/messages', 'messaging-email', 'search', []],
        'sms' => ['SMS', '/messaging/sms/threads', 'messaging-sms', 'search', []],
        'support' => ['Support', '/support/tickets', 'support', 'query', []],
        'calls' => ['Calls', '/voice/calls', 'voice-calls', 'search', []],
        'people' => ['People', '/voice/directory', 'voice-calls', 'q', []],
        'services' => ['Services', '/services', 'scheduling-settings', 'search', []],
        'templates' => ['Templates', '/messaging/templates', 'messaging-templates', 'search', ['channel' => 'EMAIL']],
        'automations' => ['Automations', '/messaging/automations', 'messaging-automations', 'search', []],
        'studio' => ['Studio', '/studio/workspaces', 'ai-editing', 'search', []],
        'requests' => ['Client requests', '/client-requests', 'shoots', 'search', []],
        'editing-requests' => ['Editing requests', '/editing-requests', 'shoots', 'search', []],
        'cubicasa' => ['Property scans', '/cubicasa/shoots', 'cubicasa-scanning', 'search', []],
        'expenses' => ['Expenses', '/admin/accounting-expenses', 'accounting', 'search', []],
        'equipment' => ['Equipment', '/admin/photographer-equipments', 'accounting', 'search', []],
        'payouts' => ['Payout reviews', '/admin/invoices/review-queue', 'accounting', 'search', []],
        'editing-earnings' => ['Editor earnings', '/admin/editors/earnings', 'accounting', 'search', []],
    ];

    public function __construct(private ReadOnlySearchSource $source, private AccountSearchQuery $accounts) {}

    public function scopes(User $user): array
    {
        $permissions = app(RolePermissionService::class);
        $scopes = [];
        if ($permissions->userCan($user, 'accounts', 'view')) $scopes[] = ['id' => 'accounts', 'label' => 'Accounts'];
        foreach (self::SOURCES as $id => [$label, , $resource]) {
            if (in_array($id, ['invoices', 'payouts'], true) && !$permissions->userCan($user, 'invoices', 'view')) continue;
            if ($id === 'people' && !$permissions->userCan($user, 'voice-calls', 'operate')) continue;
            if ($id === 'email' && !app(\App\Services\Messaging\DashboardMessagingPolicy::class)->canEmail($user)) continue;
            if (in_array($id, ['templates', 'automations'], true) && !in_array($user->role, ['admin', 'superadmin'], true)) continue;
            if ($permissions->userCan($user, $resource, 'view')) $scopes[] = ['id' => $id, 'label' => $label];
        }
        foreach (self::LOCAL_SCOPES as $id => [$label, $resource]) {
            if ($permissions->userCan($user, $resource, 'view')) $scopes[] = ['id' => $id, 'label' => $label, 'local' => true];
        }
        return $scopes;
    }

    public function search(Request $request, string $scope, string $term, array $filters, int $page = 1, int $limit = 8): array
    {
        $available = array_column($this->scopes($request->user()), 'label', 'id');
        abort_unless($scope === 'everywhere' || (isset($available[$scope]) && ($scope === 'accounts' || isset(self::SOURCES[$scope]))), 403);
        if ($term === '') return ['groups' => []];
        $groups = [];
        $serverScopes = array_filter(array_keys($available), fn ($id) => $id === 'accounts' || isset(self::SOURCES[$id]));
        foreach ($scope === 'everywhere' ? $serverScopes : [$scope] as $id) {
            $started = microtime(true);
            try {
                $group = $id === 'accounts' ? $this->searchAccounts($request, $term, $scope === 'everywhere' ? [] : $filters, $page, $limit)
                    : $this->searchSource($request, $id, $term, $scope === 'everywhere' ? [] : $filters, $page, $limit);
                $groups[] = $group;
                $this->record($id, $started, count($group['results']), 'ok');
            } catch (\Throwable $exception) {
                $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
                $this->record($id, $started, 0, $status < 500 ? 'denied' : 'error');
                if ($scope !== 'everywhere' && $status < 500) throw $exception;
                // A denied provider has no discoverable group or count globally.
                if ($status >= 500) $groups[] = ['scope' => $id, 'label' => $available[$id], 'results' => [], 'hasMore' => false, 'error' => 'unavailable'];
            }
        }
        return ['groups' => $groups];
    }

    public function preview(Request $request, string $scope, string $id, string $term, array $filters): array
    {
        // Re-run the authorized read path; no source show endpoint is used (some
        // message/detail endpoints mark records read or rebuild derived state).
        if ($scope === 'accounts') {
            $user = $this->accounts->forViewer($request->user(), '', $filters)->whereKey($id)->firstOrFail();
            $result = $this->accountResult($user);
            if (app(RolePermissionService::class)->userCan($request->user(), 'company-notes', 'view') && $user->company_notes) {
                $result['metadata']['Company notes'] = Str::limit(strip_tags($user->company_notes), 800);
            }
            if ($user->role === 'client' && app(RolePermissionService::class)->userCan($request->user(), 'shoots', 'view')) {
                try {
                    $shoots = $this->source->get($request, '/shoots', ['tab' => 'all', 'client_id' => $user->id, 'per_page' => 3]);
                    $recent = collect($shoots['data'] ?? [])->pluck('address')->filter()->take(3);
                    if ($recent->isNotEmpty()) $result['metadata']['Recent visible shoots'] = $recent->implode(' · ');
                } catch (\Throwable) {
                    // Account details remain available when related-shoot access is denied.
                }
            }
            return $result;
        }
        abort_unless(isset(self::SOURCES[$scope]), 404);
        $groups = $this->search($request, $scope, $term, $filters, 1, 100)['groups'];
        $result = collect($groups[0]['results'] ?? [])->first(fn ($row) => (string) $row['id'] === $id);
        abort_unless($result, 404);
        return $result;
    }

    private function searchAccounts(Request $request, string $term, array $filters, int $page, int $limit): array
    {
        $query = $this->accounts->forViewer($request->user(), $term, $filters);
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('CASE WHEN LOWER(email) = LOWER(?) THEN 0 WHEN LOWER(name) LIKE LOWER(?) THEN 1 ELSE 2 END', [$term, $term.'%'])
            ->orderBy('name')->orderBy('id')->offset(($page - 1) * $limit)->limit($limit)->get();
        return ['scope' => 'accounts', 'label' => 'Accounts', 'results' => $rows->map(fn ($user) => $this->accountResult($user))->all(), 'total' => $total, 'hasMore' => $page * $limit < $total];
    }

    private function accountResult(User $user): array
    {
        return ['type' => 'accounts', 'id' => (string) $user->id, 'title' => $user->name,
            'subtitle' => implode(' · ', array_filter([$user->company_name, $user->email])),
            'badges' => [$user->role, $user->account_status ?? 'active'],
            'metadata' => ['Email' => $user->email, 'Phone' => $user->resolvedPhone(), 'Company' => $user->company_name,
                'Role' => $user->role, 'Status' => $user->account_status ?? 'active'],
            'destination' => '/accounts?account='.$user->id];
    }

    private function searchSource(Request $request, string $scope, string $term, array $filters, int $page, int $limit): array
    {
        [$label, $path, , $queryKey, $defaults] = self::SOURCES[$scope];
        $allowed = ['tab', 'status', 'filter', 'scope', 'role', 'start', 'end', 'sort', 'client_id', 'photographer_id', 'sales_rep_id', 'channel_id', 'send_source', 'direction', 'disposition', 'category', 'shoot_id'];
        $parameters = array_merge($defaults, array_intersect_key($filters, array_flip($allowed)), [$queryKey => $term, 'page' => $page, 'per_page' => $limit, 'no_cache' => '1']);
        $data = $this->source->get($request, $path, $parameters);
        $rows = $data['data'] ?? $data['requests'] ?? $data['services'] ?? $data['templates'] ?? $data['workspaces'] ?? $data;
        if (isset($rows['data'])) $rows = $rows['data'];
        $rows = array_is_list($rows) ? $rows : [];
        $pagination = $data['meta']['pagination'] ?? $data['meta'] ?? $data;
        $hasPagination = isset($pagination['current_page']) || isset($pagination['last_page']) || isset($pagination['total']) || isset($pagination['per_page']);
        if (!$hasPagination && !in_array($scope, ['expenses', 'equipment', 'editing-earnings'], true)) {
            // Complete small catalog responses share the same literal matcher as
            // the facade. Paginated sources must apply text search in SQL.
            $rows = array_values(array_filter($rows, fn ($row) => $this->matches($scope, $row, $term)));
        }
        $total = $pagination['total'] ?? $pagination['count'] ?? null;
        $offset = ($page - 1) * $limit;
        if ($hasPagination) {
            // Some existing lists enforce their own page size (e.g. Property
            // Scan). Translate offsets instead of silently skipping records.
            $sourceSize = max(1, (int) ($pagination['per_page'] ?? $limit));
            $sourcePage = intdiv($offset, $sourceSize) + 1;
            if ($sourcePage !== $page) {
                $data = $this->source->get($request, $path, [...$parameters, 'page' => $sourcePage]);
                $rows = $data['data'] ?? [];
            }
            $offset %= $sourceSize;
            if ($offset + $limit > count($rows) && ($total === null || (($sourcePage - 1) * $sourceSize + count($rows)) < $total)) {
                $next = $this->source->get($request, $path, [...$parameters, 'page' => $sourcePage + 1]);
                $rows = array_merge($rows, $next['data'] ?? []);
            }
        } else {
            $total = count($rows);
        }
        $hasMore = $total !== null ? $page * $limit < $total : count($rows) > $offset + $limit;
        $results = array_map(fn ($row) => $this->result($scope, $row, $term), array_slice($rows, $offset, $limit));
        return ['scope' => $scope, 'label' => $label, 'results' => $results, 'hasMore' => $hasMore];
    }

    private function matches(string $scope, array $row, string $term): bool
    {
        $result = $this->result($scope, $row, $term);
        return Str::contains(mb_strtolower(implode(' ', [$result['id'], $result['title'], $result['subtitle'], $result['excerpt'] ?? '', ...array_values($result['metadata'])])), mb_strtolower($term));
    }

    private function result(string $scope, array $row, string $term): array
    {
        $id = (string) ($row['id'] ?? data_get($row, 'editor.id') ?? $row['tracking_code'] ?? '');
        $title = $row['name'] ?? $row['subject'] ?? $row['title'] ?? $row['address'] ?? $row['invoice_number'] ?? $row['vendor'] ?? data_get($row, 'editor.name') ?? data_get($row, 'contact.name') ?? data_get($row, 'caller_user.name') ?? $row['summary'] ?? $row['note'] ?? $row['phone'] ?? $row['from_number'] ?? ucfirst($scope).' #'.$id;
        $subtitle = implode(' · ', array_filter([
            data_get($row, 'client.name') ?? data_get($row, 'contact.name') ?? data_get($row, 'caller_user.name') ?? data_get($row, 'photographer.name') ?? data_get($row, 'salesRep.name') ?? data_get($row, 'requester.name') ?? data_get($row, 'raisedBy.name'),
            $row['status'] ?? null, $row['to_address'] ?? $row['from_phone'] ?? null,
            $row['scheduled_date'] ?? $row['created_at'] ?? null,
            isset($row['presetId']) ? str_replace('-', ' ', $row['presetId']) : null,
            data_get($row, 'shoot.address'),
        ]));
        $destination = match ($scope) {
            'shoots' => '/shoots/'.$id,
            'listings' => '/exclusive-listings/'.$id,
            'invoices' => '/accounting?invoice='.$id,
            'email' => '/messaging/email/inbox?message='.$id,
            'sms' => '/messaging/sms?thread='.$id,
            'support' => '/messaging/email/inbox?tab=support&ticket='.$id,
            'calls' => '/calls/inbox/'.$id,
            'people' => '/calls/people?search='.rawurlencode((string) $title),
            'studio' => '/ai-editing?workspace='.$id,
            'automations' => '/messaging/email/automations/'.$id,
            'templates' => '/messaging/email/templates?template='.$id,
            'services' => '/scheduling-settings?search='.rawurlencode((string) $title),
            'cubicasa' => '/cubicasa-scanning?search='.rawurlencode((string) ($row['address'] ?? $term)),
            'expenses' => '/accounting?workspace=expenses&search='.rawurlencode((string) $title),
            'equipment' => '/accounting?tab=equipments&search='.rawurlencode((string) $title),
            'payouts' => '/accounting?tab=photographers&invoice='.$id,
            'editing-earnings' => '/accounting?tab=editors&search='.rawurlencode((string) $title),
            'requests', 'editing-requests' => '/dashboard',
            default => '/dashboard',
        };
        $metadata = [];
        foreach (['status' => 'Status', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'invoice_number' => 'Invoice', 'total_amount' => 'Amount', 'balance_due' => 'Balance', 'scheduled_date' => 'Scheduled', 'created_at' => 'Created', 'category' => 'Category', 'trigger_type' => 'Trigger', 'presetId' => 'Preset', 'serial_number' => 'Serial number', 'tracking_code' => 'Tracking code', 'vendor' => 'Vendor', 'total_earned' => 'Earned', 'unpaid_amount' => 'Unpaid'] as $key => $label) {
            if (isset($row[$key]) && is_scalar($row[$key])) $metadata[$label] = $row[$key];
        }
        foreach (['editor.email' => 'Email', 'photographer.email' => 'Photographer email', 'requester.name' => 'Requester', 'shoot.address' => 'Property'] as $key => $label) {
            $value = data_get($row, $key);
            if (is_scalar($value)) $metadata[$label] = $value;
        }
        $text = (string) ($row['body_text'] ?? $row['body'] ?? $row['summary'] ?? $row['description'] ?? $row['details'] ?? data_get($row, 'latest_message.body', '') ?? '');
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        $position = mb_stripos($text, $term);
        return ['type' => $scope, 'id' => $id, 'title' => Str::limit(strip_tags((string) $title), 160),
            'subtitle' => Str::limit($subtitle, 220), 'destination' => $destination, 'metadata' => $metadata,
            'excerpt' => mb_substr($text, max(0, ($position === false ? 0 : $position) - 45), 180)];
    }

    private function record(string $scope, float $started, int $count, string $status): void
    {
        Log::channel('search')->info('search.provider', ['scope' => $scope, 'duration_ms' => round((microtime(true) - $started) * 1000), 'count' => $count, 'status' => $status]);
    }
}
