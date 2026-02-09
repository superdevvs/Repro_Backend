<?php

namespace App\Http\Controllers;

use App\Services\PayoutReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutReportController extends Controller
{
    public function __construct(private readonly PayoutReportService $service)
    {
    }

    /**
     * Get payout report data for the dashboard
     */
    public function index(Request $request)
    {
        $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
        ]);

        if ($request->filled('start') && $request->filled('end')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $end = Carbon::parse($request->input('end'))->endOfDay();
        } else {
            [$start, $end] = $this->service->lastCompletedWeekRange();
        }

        $photographerSummaries = $this->service->buildPhotographerSummaries($start, $end);
        $repSummaries = $this->service->buildSalesRepSummaries($start, $end);

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'photographers' => $photographerSummaries->values(),
            'sales_reps' => $repSummaries->values(),
            'totals' => [
                'photographer_count' => $photographerSummaries->count(),
                'photographer_total' => round($photographerSummaries->sum('gross_total'), 2),
                'sales_rep_count' => $repSummaries->count(),
                'sales_rep_commission_total' => round($repSummaries->sum('commission_total'), 2),
            ],
        ]);
    }

    /**
     * Download payout report as CSV
     */
    public function download(Request $request): StreamedResponse
    {
        $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
        ]);

        if ($request->filled('start') && $request->filled('end')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $end = Carbon::parse($request->input('end'))->endOfDay();
        } else {
            [$start, $end] = $this->service->lastCompletedWeekRange();
        }

        $photographerSummaries = $this->service->buildPhotographerSummaries($start, $end);
        $repSummaries = $this->service->buildSalesRepSummaries($start, $end);

        $filename = sprintf(
            'payout-report-%s-to-%s.csv',
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($photographerSummaries, $repSummaries, $start, $end) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Payout Report']);
            fputcsv($handle, ['Period', $start->toDateString() . ' - ' . $end->toDateString()]);
            fputcsv($handle, []);

            // Photographers section
            fputcsv($handle, ['PHOTOGRAPHERS']);
            fputcsv($handle, ['Name', 'Email', 'Shoots', 'Amount to Pay']);

            foreach ($photographerSummaries as $summary) {
                fputcsv($handle, [
                    $summary['name'],
                    $summary['email'],
                    $summary['shoot_count'],
                    number_format($summary['gross_total'], 2, '.', ''),
                ]);
            }

            fputcsv($handle, ['Total', '', $photographerSummaries->sum('shoot_count'), number_format($photographerSummaries->sum('gross_total'), 2, '.', '')]);
            fputcsv($handle, []);

            // Sales Reps section
            fputcsv($handle, ['SALES REPRESENTATIVES']);
            fputcsv($handle, ['Name', 'Email', 'Shoots', 'Gross Total', 'Commission Rate', 'Commission Amount']);

            foreach ($repSummaries as $summary) {
                fputcsv($handle, [
                    $summary['name'],
                    $summary['email'],
                    $summary['shoot_count'],
                    number_format($summary['gross_total'], 2, '.', ''),
                    $summary['commission_rate'] ? $summary['commission_rate'] . '%' : 'N/A',
                    number_format($summary['commission_total'] ?? 0, 2, '.', ''),
                ]);
            }

            fputcsv($handle, ['Total', '', $repSummaries->sum('shoot_count'), number_format($repSummaries->sum('gross_total'), 2, '.', ''), '', number_format($repSummaries->sum('commission_total'), 2, '.', '')]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
