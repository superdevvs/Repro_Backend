<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ReproAi\ShootService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ManageBookingFlow
{
    public function __construct(
        protected ShootService $shootService,
    ) {}
    /**
     * @return array{
     *   assistant_messages: array<int,array{content:string,metadata?:array}>,
     *   suggestions?: array<int,string>,
     *   actions?: array<int,array>
     * }
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        $step = $session->step ?? 'ask_booking';
        $data = $session->state_data ?? [];

        if ($step === 'ask_booking' && empty($data['shoot_id']) && ($context['entityType'] ?? null) === 'shoot' && !empty($context['entityId'])) {
            $data['shoot_id'] = $context['entityId'];
            $this->setStepAndData($session, 'show_options', $data);
            $session->save();
            return $this->showOptions($session, $message, $data);
        }

        // Check if this is an insight query from Robbie strip (handle first, before normal flow)
        if ($this->isInsightQuery($message, $context)) {
            return $this->handleInsightQuery($session, $message, $context);
        }

        return match($step) {
            'ask_booking' => $this->askBooking($session, $message, $data),
            'show_options' => $this->showOptions($session, $message, $data),
            'reschedule' => $this->handleReschedule($session, $message, $data),
            'change_services' => $this->handleChangeServices($session, $message, $data),
            'confirm_cancel' => $this->handleConfirmCancel($session, $message, $data),
            'confirm_change' => $this->confirmChange($session, $message, $data),
            default => $this->askBooking($session, $message, $data),
        };
    }

    /**
     * Check if the message is an insight-related query from Robbie strip.
     */
    protected function isInsightQuery(string $message, array $context): bool
    {
        // Check if source is from Robbie insight strip
        if (!empty($context['source']) && $context['source'] === 'robbie_insight_strip') {
            return true;
        }

        if (!empty($context['insightId']) || !empty($context['insightType'])) {
            return true;
        }

        // Check for common insight keywords
        $m = strtolower($message);
        $insightKeywords = [
            'flagged shoot', 'flagged', 'flag issue',
            'pending delivery', 'delivery sla', 'today\'s delivery',
            'stuck in editing', 'editing overdue', 'editing queue',
            'pending cancellation', 'cancellation request',
            'today\'s shoot', 'scheduled today', 'shoots today',
            'need upload', 'raw upload', 'photos uploaded',
            'overload', 'imbalance', 'editor load',
            'awaiting payment', 'payment required', 'unpaid',
            'in progress', 'shoot status',
        ];

        foreach ($insightKeywords as $keyword) {
            if (str_contains($m, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle insight-specific queries with role-aware data.
     */
    protected function handleInsightQuery(AiChatSession $session, string $message, array $context): array
    {
        $user = User::find($session->user_id);
        if (!$user) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't identify your account. Please try again.",
                    'metadata' => ['error' => 'user_not_found'],
                ]],
            ];
        }

        $insightId = $context['insightId'] ?? null;
        $insightType = $context['insightType'] ?? null;
        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $intent = $context['intent'] ?? null;

        if ($insightId) {
            switch ($insightId) {
                case 'admin-new-requests':
                    return $this->showPendingBookings($session, $user);
                case 'admin-flagged':
                    return $this->showFlaggedShoots($session, $user);
                case 'admin-pending-delivery':
                    return $this->showPendingDeliveryShoots($session, $user);
                case 'admin-stuck-editing':
                    return $this->showStuckInEditingShoots($session, $user);
                case 'admin-pending-cancel':
                    return $this->showPendingCancellations($session, $user);
                case 'admin-today-shoots':
                case 'photographer-today':
                    return $this->showTodaysShoots($session, $user);
                case 'client-payment':
                case 'rep-payment':
                    return $this->showShootsAwaitingPayment($session, $user);
                case 'client-approval':
                case 'rep-pending':
                    return $this->showPendingBookings($session, $user);
                case 'client-upcoming':
                    return $this->showUpcomingShoots($session, $user, now()->startOfDay(), null, 'Upcoming Shoots');
                case 'photographer-upload':
                    return $this->showShootsNeedingUpload($session, $user);
                case 'photographer-week':
                    return $this->showUpcomingShoots($session, $user, now()->startOfDay(), now()->endOfWeek(), 'This Week\'s Shoots');
                case 'editor-queue':
                case 'editor-assigned':
                    return $this->showEditingQueue($session, $user);
                case 'admin-all-clear':
                case 'client-default':
                case 'photographer-default':
                case 'editor-default':
                case 'rep-default':
                    return $this->showRoleBasedShoots($session, $user);
            }
        }

        if ($insightType) {
            switch ($insightType) {
                case 'new_requests':
                    return $this->showPendingBookings($session, $user);
                case 'late_raw_uploads':
                    return $this->showLateRawUploads($session, $user);
                case 'photographer_overload':
                    return $this->showPhotographerOverloadShoots($session, $user, $filters);
                case 'editor_imbalance':
                    return $this->showEditorImbalance($session, $user);
            }
        }

        if ($intent === 'accounting') {
            return $this->showShootsAwaitingPayment($session, $user);
        }

        $m = strtolower($message);

        // Route to specific handlers based on message content
        if (str_contains($m, 'new request') || str_contains($m, 'booking request') || str_contains($m, 'client request')) {
            return $this->showPendingBookings($session, $user);
        }
        if (str_contains($m, 'flagged') || str_contains($m, 'flag') || str_contains($m, 'issue')) {
            return $this->showFlaggedShoots($session, $user);
        }
        if (str_contains($m, 'pending delivery') || str_contains($m, 'delivery sla') || str_contains($m, 'today\'s delivery')) {
            return $this->showPendingDeliveryShoots($session, $user);
        }
        if (str_contains($m, 'stuck in editing') || str_contains($m, 'editing overdue')) {
            return $this->showStuckInEditingShoots($session, $user);
        }
        if (str_contains($m, 'cancellation') || str_contains($m, 'cancel request')) {
            return $this->showPendingCancellations($session, $user);
        }
        if (str_contains($m, 'today') || str_contains($m, 'scheduled today')) {
            return $this->showTodaysShoots($session, $user);
        }
        if (str_contains($m, 'overload')) {
            return $this->showPhotographerOverloadShoots($session, $user, []);
        }
        if (str_contains($m, 'imbalance') || str_contains($m, 'editor load')) {
            return $this->showEditorImbalance($session, $user);
        }
        if (str_contains($m, 'late raw') || str_contains($m, 'missing raw')) {
            return $this->showLateRawUploads($session, $user);
        }
        if (str_contains($m, 'upload') || str_contains($m, 'raw')) {
            return $this->showShootsNeedingUpload($session, $user);
        }
        if (str_contains($m, 'payment') || str_contains($m, 'unpaid')) {
            return $this->showShootsAwaitingPayment($session, $user);
        }
        if (str_contains($m, 'editing queue') || str_contains($m, 'queue')) {
            return $this->showEditingQueue($session, $user);
        }

        // Fallback: show role-based shoots
        return $this->showRoleBasedShoots($session, $user);
    }

    /**
     * Show flagged shoots - admin sees all, others see their own.
     */
    protected function showFlaggedShoots(AiChatSession $session, User $user): array
    {
        $query = $this->getShootsForUser($user)
            ->where('is_flagged', true)
            ->orderBy('updated_at', 'desc')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ No flagged shoots found. All shoots are in good standing!",
                    'metadata' => ['type' => 'flagged_shoots', 'count' => 0],
                ]],
                'suggestions' => ['Show today\'s shoots', 'Check editing queue', 'View all shoots'],
            ];
        }

        $content = "🚩 **Flagged Shoots** ({$shoots->count()} found):\n\n";
        foreach ($shoots as $shoot) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date)->format('M d, Y') : 'TBD';
            $flagReason = $shoot->admin_issue_notes ?? 'No reason specified';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📅 {$date} | Status: {$shoot->workflow_status}\n";
            $content .= "  ⚠️ Issue: {$flagReason}\n\n";
        }

        $suggestions = array_map(fn($s) => "Manage #{$s->id}", $shoots->take(3)->all());
        $suggestions[] = 'Show more details';

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'flagged_shoots', 'count' => $shoots->count()],
            ]],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Show today's shoots awaiting delivery.
     */
    protected function showPendingDeliveryShoots(AiChatSession $session, User $user): array
    {
        $today = now()->startOfDay();

        $query = $this->getShootsForUser($user)
            ->whereDate('scheduled_date', $today)
            ->whereIn('workflow_status', [
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_UPLOADED,
                Shoot::STATUS_EDITING,
            ])
            ->orderBy('time')
            ->limit(15);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ All today's shoots have been delivered or are on track!",
                    'metadata' => ['type' => 'pending_delivery', 'count' => 0],
                ]],
                'suggestions' => ['Show tomorrow\'s shoots', 'Check editing queue', 'View flagged shoots'],
            ];
        }

        $actions = $shoots->take(3)->flatMap(function ($shoot) use ($user) {
            $items = [[
                'type' => 'open_shoot',
                'label' => "Open #{$shoot->id}",
                'shootId' => $shoot->id,
            ]];

            if (in_array($user->role, ['admin', 'superadmin', 'editor'], true)
                && $shoot->workflow_status === Shoot::STATUS_EDITING) {
                $items[] = [
                    'type' => 'ready_for_review',
                    'label' => "Mark ready #{$shoot->id}",
                    'shootId' => $shoot->id,
                ];
            }

            return $items;
        })->all();

        $content = "📦 **Today's Shoots Pending Delivery** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $time = $shoot->time ?? 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  🕐 {$time} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'pending_delivery', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Prioritize delivery', 'View details', 'Contact photographer'],
        ];
    }

    /**
     * Show shoots stuck in editing (>48 hours).
     */
    protected function showStuckInEditingShoots(AiChatSession $session, User $user): array
    {
        $role = $user->role;
        $cutoff = now()->subHours(48);

        $query = $this->getShootsForUser($user)
            ->where('workflow_status', Shoot::STATUS_EDITING)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ No shoots stuck in editing. Editing workflow is flowing smoothly!",
                    'metadata' => ['type' => 'stuck_editing', 'count' => 0],
                ]],
                'suggestions' => ['Show editing queue', 'Check flagged shoots', 'View today\'s shoots'],
            ];
        }

        $actions = $shoots->take(3)->flatMap(function ($shoot) use ($role) {
            $items = [[
                'type' => 'open_shoot',
                'label' => "Open #{$shoot->id}",
                'shootId' => $shoot->id,
            ]];

            if (in_array($role, ['admin', 'superadmin', 'editing_manager'], true) && !$shoot->editor_id) {
                $items[] = [
                    'type' => 'assign_editor',
                    'label' => "Auto-assign #{$shoot->id}",
                    'shootId' => $shoot->id,
                ];
            }

            return $items;
        })->all();

        $content = "⏰ **Shoots Stuck in Editing** (>48 hours, {$shoots->count()} found):\n\n";
        foreach ($shoots as $shoot) {
            $hoursStuck = $shoot->updated_at->diffInHours(now());
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  ⚠️ Stuck for {$hoursStuck} hours | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'stuck_editing', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Escalate oldest', 'Assign editor', 'View details'],
        ];
    }

    /**
     * Show pending cancellation requests (admin only).
     */
    protected function showPendingCancellations(AiChatSession $session, User $user): array
    {
        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return [
                'assistant_messages' => [[
                    'content' => "Cancellation requests are managed by administrators.",
                    'metadata' => ['type' => 'pending_cancellations', 'access' => 'denied'],
                ]],
                'suggestions' => ['Show my shoots', 'Request cancellation'],
            ];
        }

        $shoots = Shoot::whereNotNull('cancellation_requested_at')
            ->whereNotIn('status', ['cancelled', 'canceled', 'declined'])
            ->orderBy('cancellation_requested_at')
            ->limit(10)
            ->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ No pending cancellation requests.",
                    'metadata' => ['type' => 'pending_cancellations', 'count' => 0],
                ]],
                'suggestions' => ['Show flagged shoots', 'View today\'s shoots'],
            ];
        }

        $actions = [];
        foreach ($shoots->take(3) as $shoot) {
            $actions[] = [
                'type' => 'approve_cancellation',
                'label' => "Approve #{$shoot->id}",
                'shootId' => $shoot->id,
            ];
            $actions[] = [
                'type' => 'reject_cancellation',
                'label' => "Reject #{$shoot->id}",
                'shootId' => $shoot->id,
            ];
        }

        $content = "❌ **Pending Cancellation Requests** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $requestedAt = $shoot->cancellation_requested_at ? Carbon::parse($shoot->cancellation_requested_at)->format('M d, Y H:i') : 'Unknown';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📝 Requested: {$requestedAt}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'type' => 'pending_cancellations',
                    'count' => $shoots->count(),
                    'actions' => $actions,
                ],
            ]],
            'suggestions' => ['Approve cancellation', 'Decline request', 'Contact client'],
        ];
    }

    /**
     * Show today's scheduled shoots.
     */
    protected function showTodaysShoots(AiChatSession $session, User $user): array
    {
        $today = now()->startOfDay();

        $query = $this->getShootsForUser($user)
            ->whereDate('scheduled_date', $today)
            ->orderBy('time')
            ->limit(20);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "📅 No shoots scheduled for today.",
                    'metadata' => ['type' => 'todays_shoots', 'count' => 0],
                ]],
                'suggestions' => ['Show tomorrow\'s shoots', 'Book a new shoot', 'Check availability'],
            ];
        }

        $actions = $shoots->take(3)->flatMap(function ($shoot) use ($role) {
            $items = [[
                'type' => 'open_shoot',
                'label' => "Open #{$shoot->id}",
                'shootId' => $shoot->id,
            ]];

            if (in_array($role, ['admin', 'superadmin', 'editing_manager'], true) && !$shoot->editor_id) {
                $items[] = [
                    'type' => 'assign_editor',
                    'label' => "Auto-assign #{$shoot->id}",
                    'shootId' => $shoot->id,
                ];
            }

            return $items;
        })->all();

        $content = "📅 **Today's Shoots** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $time = $shoot->time ?? 'TBD';
            $photographer = $shoot->photographer ? $shoot->photographer->name : 'Unassigned';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  🕐 {$time} | 📷 {$photographer} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'todays_shoots', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['View details', 'Contact photographer', 'Check weather'],
        ];
    }

    /**
     * Show shoots needing RAW upload (photographer/admin).
     */
    protected function showShootsNeedingUpload(AiChatSession $session, User $user): array
    {
        $query = $this->getShootsForUser($user)
            ->whereNull('photos_uploaded_at')
            ->whereDate('scheduled_date', '<', now())
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->orderBy('scheduled_date', 'desc')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ All past shoots have RAW files uploaded!",
                    'metadata' => ['type' => 'needs_upload', 'count' => 0],
                ]],
                'suggestions' => ['Show today\'s shoots', 'Check editing queue'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "📤 **Shoots Needing RAW Upload** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date)->format('M d, Y') : 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📅 Shot on: {$date} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'missing_uploads', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['View shoot', 'Check schedule'],
        ];
    }

    /**
     * Show shoots awaiting payment.
     */
    protected function showShootsAwaitingPayment(AiChatSession $session, User $user): array
    {
        $query = $this->getShootsForUser($user)
            ->where('payment_status', '!=', 'paid')
            ->where('workflow_status', Shoot::STATUS_DELIVERED)
            ->orderBy('updated_at', 'desc')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ All delivered shoots have been paid!",
                    'metadata' => ['type' => 'awaiting_payment', 'count' => 0],
                ]],
                'suggestions' => ['Show my shoots', 'View invoices'],
            ];
        }

        $actions = [];
        foreach ($shoots->take(3) as $shoot) {
            $actions[] = [
                'type' => 'create_checkout_link',
                'label' => "Payment link #{$shoot->id}",
                'shootId' => $shoot->id,
            ];
        }
        if ($user->role === 'client') {
            $actions[] = [
                'type' => 'pay_multiple_shoots',
                'label' => 'Pay all unpaid',
                'shootIds' => $shoots->pluck('id')->all(),
            ];
        }

        $totalDue = $shoots->sum('total_quote');
        $content = "💰 **Shoots Awaiting Payment** ({$shoots->count()}, Total: \${$totalDue}):\n\n";
        foreach ($shoots as $shoot) {
            $amount = $shoot->total_quote ?? 0;
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  💵 Amount: \${$amount} | Status: {$shoot->payment_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'type' => 'awaiting_payment',
                    'count' => $shoots->count(),
                    'total_due' => $totalDue,
                    'actions' => $actions,
                ],
            ]],
            'suggestions' => ['Pay now', 'View invoice', 'Request extension'],
        ];
    }

    /**
     * Show pending booking requests (requested status).
     */
    protected function showPendingBookings(AiChatSession $session, User $user): array
    {
        $query = $this->getShootsForUser($user)
            ->where('status', Shoot::STATUS_REQUESTED)
            ->orderBy('scheduled_date')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ No pending booking requests right now.",
                    'metadata' => ['type' => 'pending_bookings', 'count' => 0],
                ]],
                'suggestions' => ['Show upcoming shoots', 'Book a new shoot'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "📝 **Pending Booking Requests** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date)->format('M d, Y') : 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📅 Requested for: {$date}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'pending_bookings', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Approve oldest', 'View request details', 'Contact client'],
        ];
    }

    /**
     * Show upcoming shoots in a date range.
     */
    protected function showUpcomingShoots(
        AiChatSession $session,
        User $user,
        \Carbon\Carbon $startDate,
        ?\Carbon\Carbon $endDate,
        string $title
    ): array {
        $query = $this->getShootsForUser($user)
            ->whereDate('scheduled_date', '>=', $startDate)
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->orderBy('scheduled_date')
            ->limit(15);

        if ($endDate) {
            $query->whereDate('scheduled_date', '<=', $endDate);
        }

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "📅 No upcoming shoots in this period.",
                    'metadata' => ['type' => 'upcoming_shoots', 'count' => 0],
                ]],
                'suggestions' => ['Book a new shoot', 'Check availability'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "📅 **{$title}** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date)->format('M d, Y') : 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📅 {$date} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'upcoming_shoots', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['View details', 'Manage a booking'],
        ];
    }

    /**
     * Show editing queue (editor/admin).
     */
    protected function showEditingQueue(AiChatSession $session, User $user): array
    {
        $role = $user->role;
        
        $query = Shoot::whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING]);
        
        if ($role === 'editor') {
            $query->where(function ($q) use ($user) {
                $q->where('editor_id', $user->id)
                  ->orWhereNull('editor_id');
            });
        } elseif (!in_array($role, ['admin', 'superadmin'])) {
            return [
                'assistant_messages' => [[
                    'content' => "The editing queue is available for editors and administrators.",
                    'metadata' => ['type' => 'editing_queue', 'access' => 'denied'],
                ]],
                'suggestions' => ['Show my shoots', 'Check shoot status'],
            ];
        }

        $shoots = $query->orderBy('created_at')->limit(15)->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "✅ Editing queue is empty. All caught up!",
                    'metadata' => ['type' => 'editing_queue', 'count' => 0],
                ]],
                'suggestions' => ['Show delivered shoots', 'View completed edits'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "🎨 **Editing Queue** ({$shoots->count()} shoots):\n\n";
        foreach ($shoots as $shoot) {
            $uploadedAt = $shoot->photos_uploaded_at ? Carbon::parse($shoot->photos_uploaded_at)->diffForHumans() : 'Unknown';
            $editor = $shoot->editor ? $shoot->editor->name : 'Unassigned';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📤 Uploaded: {$uploadedAt} | 🎨 Editor: {$editor}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'editing_queue', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Start editing oldest', 'Assign editor', 'View details'],
        ];
    }

    /**
     * Show tomorrow's shoots for an overloaded photographer.
     */
    protected function showPhotographerOverloadShoots(AiChatSession $session, User $user, array $filters): array
    {
        $date = $this->parseFilterDate($filters['date'] ?? null) ?? now()->addDay()->startOfDay();
        $photographerId = $filters['photographerId'] ?? null;

        $query = $this->getShootsForUser($user)
            ->whereDate('scheduled_date', $date)
            ->where('status', Shoot::STATUS_SCHEDULED)
            ->orderBy('time')
            ->limit(15);

        if ($photographerId) {
            $query->where('photographer_id', $photographerId);
        }

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "No shoots found for that photographer on {$date->toDateString()}.",
                    'metadata' => ['type' => 'photographer_overload', 'count' => 0],
                ]],
                'suggestions' => ['Show today\'s shoots', 'Check editing queue'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "📸 **Photographer Overload** ({$shoots->count()} shoots on {$date->toDateString()}):\n\n";
        foreach ($shoots as $shoot) {
            $time = $shoot->time ?? 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  🕐 {$time} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'photographer_overload', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['View details', 'Balance schedule'],
        ];
    }

    /**
     * Show editor queue imbalance summary with quick actions.
     */
    protected function showEditorImbalance(AiChatSession $session, User $user): array
    {
        $role = $user->role;
        if (!in_array($role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return [
                'assistant_messages' => [[
                    'content' => "Editor workload details are managed by administrators.",
                    'metadata' => ['type' => 'editor_imbalance', 'access' => 'denied'],
                ]],
                'suggestions' => ['Show editing queue', 'Show my shoots'],
            ];
        }

        $editorLoads = Shoot::whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING])
            ->whereNotNull('editor_id')
            ->select('editor_id', 
                \DB::raw('count(*) as total')
            )
            ->groupBy('editor_id')
            ->orderByDesc('total')
            ->get();

        if ($editorLoads->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "No editor workload data available yet.",
                    'metadata' => ['type' => 'editor_imbalance', 'count' => 0],
                ]],
                'suggestions' => ['Show editing queue', 'View uploads'],
            ];
        }

        $editorIds = $editorLoads->pluck('editor_id');
        $editors = User::whereIn('id', $editorIds)->get(['id', 'name'])->keyBy('id');
        $unassignedShoots = Shoot::whereIn('workflow_status', [Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING])
            ->whereNull('editor_id')
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $actions = $unassignedShoots->flatMap(function ($shoot) {
            return [[
                'type' => 'open_shoot',
                'label' => "Open #{$shoot->id}",
                'shootId' => $shoot->id,
            ], [
                'type' => 'assign_editor',
                'label' => "Auto-assign #{$shoot->id}",
                'shootId' => $shoot->id,
            ]];
        })->all();

        $content = "⚖️ **Editor Load Imbalance**\n\n";
        foreach ($editorLoads->take(5) as $load) {
            $name = $editors[$load->editor_id]->name ?? "Editor {$load->editor_id}";
            $content .= "• {$name}: {$load->total} shoot(s)\n";
        }

        if ($unassignedShoots->isNotEmpty()) {
            $content .= "\nUnassigned shoots:\n";
            foreach ($unassignedShoots as $shoot) {
                $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            }
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'editor_imbalance', 'count' => $editorLoads->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Balance editors', 'View editing queue'],
        ];
    }

    protected function parseFilterDate(?string $date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Show role-based shoots as fallback.
     */
    protected function showRoleBasedShoots(AiChatSession $session, User $user): array
    {
        $query = $this->getShootsForUser($user)
            ->orderBy('scheduled_date', 'desc')
            ->limit(10);

        $shoots = $query->get();

        if ($shoots->isEmpty()) {
            return [
                'assistant_messages' => [[
                    'content' => "You don't have any shoots yet. Ready to book your first shoot?",
                    'metadata' => ['type' => 'role_shoots', 'count' => 0],
                ]],
                'suggestions' => ['Book a new shoot', 'Check availability'],
            ];
        }

        $actions = $shoots->take(3)->map(fn($shoot) => [
            'type' => 'open_shoot',
            'label' => "Open #{$shoot->id}",
            'shootId' => $shoot->id,
        ])->all();

        $content = "📋 **Your Recent Shoots** ({$shoots->count()}):\n\n";
        foreach ($shoots as $shoot) {
            $date = $shoot->scheduled_date ? Carbon::parse($shoot->scheduled_date)->format('M d, Y') : 'TBD';
            $content .= "• **#{$shoot->id}** - {$shoot->address}, {$shoot->city}\n";
            $content .= "  📅 {$date} | Status: {$shoot->workflow_status}\n\n";
        }

        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => ['type' => 'role_shoots', 'count' => $shoots->count(), 'actions' => $actions],
            ]],
            'suggestions' => ['Manage a booking', 'Book new shoot', 'View details'],
        ];
    }

    /**
     * Get shoots query filtered by user role.
     */
    protected function getShootsForUser(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $role = $user->role;
        $query = Shoot::query()->with(['photographer:id,name', 'editor:id,name', 'client:id,name']);

        switch ($role) {
            case 'admin':
            case 'superadmin':
                // Admins see all shoots
                break;
            case 'photographer':
                $query->where('photographer_id', $user->id);
                break;
            case 'editor':
                $query->where('editor_id', $user->id);
                break;
            case 'client':
                $query->where('client_id', $user->id);
                break;
            case 'salesRep':
                // Sales reps see shoots from clients they created
                $clientIds = User::where('created_by_id', $user->id)->pluck('id');
                $query->whereIn('client_id', $clientIds);
                break;
            default:
                // Default: only their own shoots as client
                $query->where('client_id', $user->id);
        }

        return $query;
    }

    private function askBooking(AiChatSession $session, string $message, array $data): array
    {
        // Check if message matches a shoot from suggestions
        if (empty($data['shoot_id'])) {
            $upcomingShoots = $this->shootService->listUpcomingForUser($session->user_id, 10);
            
            // Try to match message to a shoot - improved matching
            $messageLower = strtolower(trim($message));
            foreach ($upcomingShoots as $shoot) {
                // Match by ID (e.g., "#123" or "123")
                if (preg_match('/#?(\d+)/', $message, $matches)) {
                    if ((int)$matches[1] === $shoot->id) {
                        $data['shoot_id'] = $shoot->id;
                        break;
                    }
                }
                // Match by address
                if (str_contains($messageLower, strtolower($shoot->address))) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
                // Match by city
                if (!empty($shoot->city) && str_contains($messageLower, strtolower($shoot->city))) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
            }
        }

        if (!empty($data['shoot_id'])) {
            $this->setStepAndData($session, 'show_options', $data);
            $session->save();
            return $this->showOptions($session, $message, $data);
        }

        // First time asking - show upcoming shoots
        $upcomingShoots = $this->shootService->listUpcomingForUser($session->user_id, 10);
        
        if ($upcomingShoots->isEmpty()) {
            // Also check for past shoots to manage
            $allShoots = Shoot::where(function ($query) use ($session) {
                $query->where('client_id', $session->user_id)
                      ->orWhere('rep_id', $session->user_id);
            })
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_at', 'desc')
            ->limit(10)
            ->get();
            
            if ($allShoots->isEmpty()) {
                return [
                    'assistant_messages' => [[
                        'content' => "You don't have any bookings to manage.",
                        'metadata' => ['step' => 'ask_booking'],
                    ]],
                    'suggestions' => [
                        'Book a new shoot',
                        'Check availability',
                    ],
                ];
            }
            
            $suggestions = [];
            foreach ($allShoots as $shoot) {
                $dateStr = $shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y') : 'TBD';
                $label = "#{$shoot->id} - {$shoot->address}, {$shoot->city} - {$dateStr}";
                $suggestions[] = $label;
            }
            
            $this->setStepAndData($session, 'ask_booking', $data);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "Which booking would you like to manage?",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
                'suggestions' => $suggestions,
            ];
        }

        $this->setStepAndData($session, 'ask_booking', $data);
        $session->save();

        $suggestions = [];
        foreach ($upcomingShoots as $shoot) {
            $dateStr = $shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y g:i A') : 'TBD';
            $label = "#{$shoot->id} - {$shoot->address}, {$shoot->city} - {$dateStr}";
            $suggestions[] = $label;
        }

        return [
            'assistant_messages' => [[
                'content' => "Which booking would you like to manage? (Next 30 days)",
                'metadata' => ['step' => 'ask_booking'],
            ]],
            'suggestions' => $suggestions,
            'meta' => [
                'shoots' => $upcomingShoots->map(fn($s) => [
                    'id' => $s->id,
                    'address' => $s->address,
                    'city' => $s->city,
                    'scheduled_at' => $s->scheduled_at?->toIso8601String(),
                ])->toArray(),
            ],
        ];
    }

    private function showOptions(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $user = User::find($session->user_id);
        if (!$user) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't identify your account. Please try again.",
                    'metadata' => ['error' => 'user_not_found'],
                ]],
            ];
        }

        $shoot = $this->getShootsForUser($user)
            ->where('shoots.id', $shootId)
            ->first();
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking. Let's try again.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
                'suggestions' => ['Show my bookings'],
            ];
        }

        // Check if user selected an action
        $m = strtolower($message);
        if (str_contains($m, 'reschedule')) {
            $this->setStepAndData($session, 'reschedule', $data);
            return $this->handleReschedule($session, $message, $data);
        } elseif (str_contains($m, 'cancel')) {
            $this->setStepAndData($session, 'confirm_cancel', $data);
            return $this->handleConfirmCancel($session, $message, $data);
        } elseif (str_contains($m, 'change') && (str_contains($m, 'service') || str_contains($m, 'services'))) {
            $this->setStepAndData($session, 'change_services', $data);
            return $this->handleChangeServices($session, $message, $data);
        }

        // First time showing options
        $this->setStepAndData($session, 'show_options', $data);

        $shootInfo = "Booking #{$shoot->id}\n";
        $shootInfo .= "Property: {$shoot->address}, {$shoot->city}\n";
        $shootInfo .= "Date: " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y g:i A') : 'TBD') . "\n";
        $shootInfo .= "Status: {$shoot->status}";

        return [
            'assistant_messages' => [[
                'content' => "Here's the booking:\n\n{$shootInfo}\n\nWhat would you like to do?",
                'metadata' => ['step' => 'show_options', 'shoot_id' => $shoot->id],
            ]],
            'suggestions' => [
                'Reschedule',
                'Cancel',
                'Change services',
                'View details',
            ],
        ];
    }

    private function handleReschedule(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        // If we have both date and time, update the shoot
        if (!empty($data['new_date']) && !empty($data['new_time'])) {
            $user = User::find($session->user_id);
            $updateData = [
                'date' => $data['new_date'],
                'time_window' => $data['new_time'],
            ];
            
            try {
                $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
                
                $this->setStepAndData($session, null, []);
                $session->save();
                
                $formattedDate = Carbon::parse($data['new_date'])->format('M d, Y');
                return [
                    'assistant_messages' => [[
                        'content' => "✅ I've rescheduled the shoot to **{$formattedDate}** at **{$data['new_time']}**.",
                        'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                    ]],
                    'suggestions' => [
                        'Manage another booking',
                        'Book a new shoot',
                    ],
                ];
            } catch (\Exception $e) {
                return [
                    'assistant_messages' => [[
                        'content' => "❌ Failed to reschedule: " . $e->getMessage(),
                        'metadata' => ['step' => 'reschedule', 'error' => $e->getMessage()],
                    ]],
                    'suggestions' => [
                        'Try again',
                        'Go back',
                    ],
                ];
            }
        }

        // Parse date from message
        $messageLower = strtolower(trim($message));
        if (empty($data['new_date']) && !empty(trim($message)) && !str_contains($messageLower, 'reschedule')) {
            $parsedDate = $this->parseDateFromMessage($message);
            $parsedTime = $this->parseTimeFromMessage($message);
            
            if ($parsedDate) {
                $data['new_date'] = $parsedDate;
                
                // If time was also in the message, apply it directly
                if ($parsedTime) {
                    $data['new_time'] = $parsedTime;
                    $this->setStepAndData($session, 'reschedule', $data);
                    $session->save();
                    
                    // Recurse to apply the update
                    return $this->handleReschedule($session, '', $data);
                }
                
                $this->setStepAndData($session, 'reschedule', $data);
                $session->save();
                
                $formattedDate = Carbon::parse($parsedDate)->format('M d, Y');
                return [
                    'assistant_messages' => [[
                        'content' => "What time works best on **{$formattedDate}**?",
                        'metadata' => ['step' => 'reschedule'],
                    ]],
                    'suggestions' => [
                        'Morning (10 AM)',
                        'Afternoon (2 PM)',
                        'Evening (5 PM)',
                        'Flexible',
                    ],
                ];
            }
        }

        // If we have date but not time, capture time
        if (!empty($data['new_date']) && empty($data['new_time']) && !empty(trim($message))) {
            $data['new_time'] = $message;
            $this->setStepAndData($session, 'reschedule', $data);
            $session->save();
            
            // Recurse to apply the update
            return $this->handleReschedule($session, '', $data);
        }

        // First time asking for reschedule
        $this->setStepAndData($session, 'reschedule', $data);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "What date would you like to reschedule to?",
                'metadata' => ['step' => 'reschedule'],
            ]],
            'suggestions' => [
                'Tomorrow',
                'Next week',
                'This weekend',
            ],
        ];
    }

    private function parseDateFromMessage(string $message): ?string
    {
        $messageLower = strtolower(trim($message));
        
        if (str_contains($messageLower, 'tomorrow')) {
            return now()->addDay()->format('Y-m-d');
        }
        if (str_contains($messageLower, 'today')) {
            return now()->format('Y-m-d');
        }
        if ($messageLower === 'this weekend' || $messageLower === 'weekend' || str_contains($messageLower, 'saturday')) {
            return now()->next(Carbon::SATURDAY)->format('Y-m-d');
        }
        if (str_contains($messageLower, 'sunday')) {
            return now()->next(Carbon::SUNDAY)->format('Y-m-d');
        }
        if ($messageLower === 'this week' || $messageLower === 'week') {
            $next = now()->addDay();
            if ($next->isWeekend()) {
                $next = now()->next(Carbon::MONDAY);
            }
            return $next->format('Y-m-d');
        }
        if ($messageLower === 'next week') {
            return now()->addWeek()->startOfWeek()->format('Y-m-d');
        }
        
        // Try ISO format: YYYY-MM-DD
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $matches)) {
            return $matches[1];
        }
        
        // Try US format: MM/DD/YYYY
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $message, $matches)) {
            try {
                return Carbon::createFromFormat('m/d/Y', $matches[1])->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }
        
        // Try parsing natural language dates
        try {
            $parsed = Carbon::parse($message);
            if ($parsed->isFuture() || $parsed->isToday()) {
                return $parsed->format('Y-m-d');
            }
        } catch (\Exception $e) {
            // Not parseable
        }
        
        return null;
    }

    private function parseTimeFromMessage(string $message): ?string
    {
        $messageLower = strtolower($message);
        
        if (str_contains($messageLower, 'morning')) {
            return 'Morning';
        }
        if (str_contains($messageLower, 'afternoon')) {
            return 'Afternoon';
        }
        if (str_contains($messageLower, 'evening') || str_contains($messageLower, 'golden hour')) {
            return 'Evening';
        }
        
        return null;
    }

    private function handleChangeServices(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        // If we have new services, update
        if (!empty($data['new_service_ids'])) {
            $user = User::find($session->user_id);
            $updateData = ['service_ids' => $data['new_service_ids']];
            
            $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
            
            $this->setStepAndData($session, null, null);
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've updated the services for this booking.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // Parse services from message if provided
        if (!empty(trim($message)) && empty($data['new_service_ids'])) {
            $serviceIds = $this->inferServiceIdsFromText($message);
            if (!empty($serviceIds)) {
                $data['new_service_ids'] = $serviceIds;
                $user = User::find($session->user_id);
                $updateData = ['service_ids' => $serviceIds];
                
                $this->shootService->updateFromAiConversation($shoot, $updateData, $user);
                
                $this->setStepAndData($session, null, null);
                return [
                    'assistant_messages' => [[
                        'content' => "✅ I've updated the services for this booking.",
                        'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                    ]],
                    'suggestions' => [
                        'Manage another booking',
                        'Book a new shoot',
                    ],
                ];
            }
        }

        // Ask for new services
        $this->setStepAndData($session, 'change_services', $data);
        return [
            'assistant_messages' => [[
                'content' => "What services would you like for this booking?",
                'metadata' => ['step' => 'change_services'],
            ]],
            'suggestions' => [
                'Photos only',
                'Photos + video',
                'Photos + video + drone',
                'Full package',
            ],
        ];
    }

    private function inferServiceIdsFromText(string $text): array
    {
        $t = strtolower($text);
        $serviceIds = [];

        $services = \App\Models\Service::all(['id', 'name']);
        foreach ($services as $service) {
            $serviceName = strtolower($service->name);
            if (str_contains($t, $serviceName) || str_contains($serviceName, $t)) {
                $serviceIds[] = $service->id;
            }
        }

        // Fallback: if no matches, try common keywords
        if (empty($serviceIds)) {
            if (str_contains($t, 'photo')) {
                $photoService = \App\Models\Service::where('name', 'like', '%photo%')->first();
                if ($photoService) $serviceIds[] = $photoService->id;
            }
            if (str_contains($t, 'video')) {
                $videoService = \App\Models\Service::where('name', 'like', '%video%')->first();
                if ($videoService) $serviceIds[] = $videoService->id;
            }
            if (str_contains($t, 'drone')) {
                $droneService = \App\Models\Service::where('name', 'like', '%drone%')->first();
                if ($droneService) $serviceIds[] = $droneService->id;
            }
        }

        return $serviceIds;
    }

    private function handleConfirmCancel(AiChatSession $session, string $message, array $data): array
    {
        $shootId = $data['shoot_id'] ?? null;
        if (!$shootId) {
            return $this->askBooking($session, $message, $data);
        }

        $shoot = Shoot::find($shootId);
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that booking.",
                    'metadata' => ['step' => 'ask_booking'],
                ]],
            ];
        }

        $m = strtolower($message);
        if (str_contains($m, 'yes') || str_contains($m, 'confirm')) {
            $user = User::find($session->user_id);
            $this->shootService->cancelShoot($shoot, $user);
            
            $this->setStepAndData($session, null, null);
            return [
                'assistant_messages' => [[
                    'content' => "✅ I've cancelled the booking for {$shoot->address}, {$shoot->city}.",
                    'metadata' => ['step' => 'done', 'shoot_id' => $shoot->id],
                ]],
                'suggestions' => [
                    'Manage another booking',
                    'Book a new shoot',
                ],
            ];
        }

        // Ask for confirmation
        $this->setStepAndData($session, 'confirm_cancel', $data);
        return [
            'assistant_messages' => [[
                'content' => "Are you sure you want to cancel the booking for {$shoot->address}, {$shoot->city} on " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y') : 'TBD') . "?",
                'metadata' => ['step' => 'confirm_cancel'],
            ]],
            'suggestions' => [
                'Yes, cancel it',
                'No, keep it',
            ],
        ];
    }

    private function confirmChange(AiChatSession $session, string $message, array $data): array
    {
        return [
            'assistant_messages' => [[
                'content' => "I've noted your request. This feature is coming soon!",
                'metadata' => ['step' => 'confirm_change'],
            ]],
            'suggestions' => [
                'Book a new shoot',
                'Check availability',
            ],
        ];
    }

    protected function setStepAndData(AiChatSession $session, ?string $step = null, ?array $data = null): void
    {
        if ($step !== null && Schema::hasColumn('ai_chat_sessions', 'step')) {
            $session->step = $step;
        }
        if ($data !== null && Schema::hasColumn('ai_chat_sessions', 'state_data')) {
            $session->state_data = $data;
        }
    }
}

