<?php

namespace App\Http\Controllers;

use App\Services\SalesReportService;
use App\Services\MailService;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    protected $salesReportService;
    protected $mailService;

    public function __construct(SalesReportService $salesReportService, MailService $mailService)
    {
        $this->salesReportService = $salesReportService;
        $this->mailService = $mailService;
    }

    /**
     * Get weekly sales report for authenticated sales rep
     */
    public function myWeeklyReport(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'salesRep') {
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

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $salesRep = User::findOrFail($salesRepId);

        if ($salesRep->role !== 'salesRep') {
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

        if (!in_array($user->role, ['admin', 'super_admin'])) {
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
}


