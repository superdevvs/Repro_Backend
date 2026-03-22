<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Models\User;
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

        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->startOfWeek()->subWeek();
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now()->startOfWeek()->subWeek()->endOfWeek();

        $report = $this->salesReportService->generateWeeklyReportForSalesRep($user, $startDate, $endDate);

        return response()->json($report);
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

        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->startOfWeek()->subWeek();
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now()->startOfWeek()->subWeek()->endOfWeek();

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
}


