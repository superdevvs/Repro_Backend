<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Models\User;
use App\Support\ReportingWeek;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager'];

    protected $salesReportService;
    protected $mailService;
    protected $automationService;

    public function __construct(SalesReportService $salesReportService, MailService $mailService, AutomationService $automationService)
    {
        $this->salesReportService = $salesReportService;
        $this->mailService = $mailService;
        $this->automationService = $automationService;
    }

    /**
     * Get weekly sales report for authenticated sales rep
     */
    public function myWeeklyReport(Request $request)
    {
        $user = $request->user();

        if (!$this->salesReportService->isSalesRep($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        [$startDate, $endDate] = $this->resolveWeeklyReportWindow($request);

        $report = $this->salesReportService->generateWeeklyReportForSalesRep($user, $startDate, $endDate);

        return response()->json($report);
    }

    /**
     * Get sales summary for authenticated sales rep
     */
    public function mySummary(Request $request)
    {
        $user = $request->user();

        if (!$this->salesReportService->isSalesRep($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        [$startDate, $endDate] = $this->resolveSummaryWindow(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $report = $this->salesReportService->generateSummaryForSalesRep($user, $startDate, $endDate);

        return response()->json($report);
    }

    /**
     * Get inactive-client hit list for authenticated sales rep.
     */
    public function myInactiveClients(Request $request)
    {
        $user = $request->user();

        if (!$this->salesReportService->isSalesRep($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'state' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json(
            $this->salesReportService->generateInactiveClientsForSalesRep(
                $user,
                (int) ($validated['days'] ?? 90),
                $validated['state'] ?? null,
            ),
        );
    }

    /**
     * Inactive-client report (feature #9) for admins (all clients) or sales reps (own clients).
     * Filters: `days` (default 90), `state`, and admin-only `sales_rep_id`.
     */
    public function inactiveClientsReport(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'state' => ['nullable', 'string', 'max:50'],
            'sales_rep_id' => ['nullable', 'integer'],
        ]);

        $days = (int) ($validated['days'] ?? 90);
        $state = $validated['state'] ?? null;

        $isAdmin = $user && in_array(strtolower((string) $user->role), self::ADMIN_ROLES, true);

        if ($isAdmin) {
            return response()->json(
                $this->salesReportService->generateInactiveClientsForAdmin(
                    $days,
                    $state,
                    $validated['sales_rep_id'] ?? null,
                ),
            );
        }

        if ($this->salesReportService->isSalesRep($user)) {
            return response()->json(
                $this->salesReportService->generateInactiveClientsForSalesRep($user, $days, $state),
            );
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    /**
     * Admin: Get weekly sales report for a specific sales rep
     */
    public function salesRepReport(Request $request, $salesRepId)
    {
        $user = $request->user();

        if (!$this->isAdminLike($user?->role)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $salesRep = User::findOrFail($salesRepId);

        if (!$this->salesReportService->isSalesRep($salesRep)) {
            return response()->json(['message' => 'User is not a sales rep'], 422);
        }

        [$startDate, $endDate] = $this->resolveWeeklyReportWindow($request);

        $report = $this->salesReportService->generateWeeklyReportForSalesRep($salesRep, $startDate, $endDate);

        return response()->json($report);
    }

    /**
     * Admin: Send weekly sales reports to all sales reps
     */
    public function sendWeeklyReports(Request $request)
    {
        $user = $request->user();

        if (!$this->isAdminLike($user?->role)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        [$startDate, $endDate] = $this->salesReportService->getLastCompletedWeek();

        $reports = $this->salesReportService->generateWeeklyReportsForAllSalesReps($startDate, $endDate);

        $sent = 0;
        $failed = 0;

        foreach ($reports as $reportData) {
            $salesRep = User::find($reportData['sales_rep']['id']);
            if ($salesRep) {
                if ($this->mailService->sendWeeklySalesReportEmail($salesRep, $reportData)) {
                    $sent++;
                    $this->automationService->handleEvent('WEEKLY_SALES_REPORT', [
                        'rep' => $salesRep,
                        'account_id' => $salesRep->id,
                        'report' => $reportData,
                    ]);
                } else {
                    $failed++;
                }
            }
        }

        return response()->json([
            'message' => 'Weekly sales reports sent',
            'sent' => $sent,
            'failed' => $failed,
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
        ]);
    }

    private function isAdminLike(?string $role): bool
    {
        $normalizedRole = strtolower(str_replace(['_', '-'], '', (string) $role));
        $normalizedAllowed = array_map(
            fn (string $item) => strtolower(str_replace(['_', '-'], '', $item)),
            self::ADMIN_ROLES
        );

        return in_array($normalizedRole, $normalizedAllowed, true);
    }

    private function resolveSummaryWindow(?string $startDate, ?string $endDate): array
    {
        $resolvedEnd = $endDate
            ? Carbon::createFromFormat('Y-m-d', $endDate)
            : Carbon::today();

        $resolvedStart = $startDate
            ? Carbon::createFromFormat('Y-m-d', $startDate)
            : $resolvedEnd->copy()->subDays(29);

        return [$resolvedStart->startOfDay(), $resolvedEnd->endOfDay()];
    }

    private function resolveWeeklyReportWindow(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $start = !empty($validated['start_date']) ? $validated['start_date'] : null;
        $end = !empty($validated['end_date']) ? $validated['end_date'] : null;

        if ($start === null && $end === null) {
            return $this->salesReportService->getLastCompletedWeek();
        }

        $start ??= $end;
        $end ??= $start;

        return ReportingWeek::normalizeRange($start, $end);
    }
}
