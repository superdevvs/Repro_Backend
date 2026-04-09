<?php

namespace App\Http\Controllers;

use App\Services\PayoutReportService;
use App\Services\Messaging\MessagingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayoutReportController extends Controller
{
    public function __construct(
        private readonly PayoutReportService $service,
        private readonly MessagingService $messagingService,
    )
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
            'role' => 'nullable|string|in:all,photographer,salesRep,editor',
        ]);

        if ($request->filled('start') && $request->filled('end')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $end = Carbon::parse($request->input('end'))->endOfDay();
        } else {
            [$start, $end] = $this->service->lastCompletedWeekRange();
        }

        $role = $request->input('role', 'all');
        $photographerSummaries = in_array($role, ['all', 'photographer'], true)
            ? $this->service->buildPhotographerSummaries($start, $end)
            : collect();
        $repSummaries = in_array($role, ['all', 'salesRep'], true)
            ? $this->service->buildSalesRepSummaries($start, $end)
            : collect();
        $editorSummaries = in_array($role, ['all', 'editor'], true)
            ? $this->service->buildEditorSummaries($start, $end)
            : collect();

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'role' => $role,
            'photographers' => $photographerSummaries->values(),
            'editors' => $editorSummaries->values(),
            'sales_reps' => $repSummaries->values(),
            'totals' => [
                'photographer_count' => $photographerSummaries->count(),
                'photographer_total' => round($photographerSummaries->sum('gross_total'), 2),
                'editor_count' => $editorSummaries->count(),
                'editor_total' => round($editorSummaries->sum('gross_total'), 2),
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
            'role' => 'nullable|string|in:all,photographer,salesRep,editor',
        ]);

        if ($request->filled('start') && $request->filled('end')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $end = Carbon::parse($request->input('end'))->endOfDay();
        } else {
            [$start, $end] = $this->service->lastCompletedWeekRange();
        }

        $role = $request->input('role', 'all');
        $photographerSummaries = in_array($role, ['all', 'photographer'], true)
            ? $this->service->buildPhotographerSummaries($start, $end)
            : collect();
        $repSummaries = in_array($role, ['all', 'salesRep'], true)
            ? $this->service->buildSalesRepSummaries($start, $end)
            : collect();
        $editorSummaries = in_array($role, ['all', 'editor'], true)
            ? $this->service->buildEditorSummaries($start, $end)
            : collect();

        $filename = sprintf(
            'payout-report-%s-to-%s.csv',
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($photographerSummaries, $editorSummaries, $repSummaries, $start, $end) {
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

            fputcsv($handle, ['EDITORS']);
            fputcsv($handle, ['Name', 'Email', 'Shoots', 'Services', 'Amount to Pay', 'Unpaid Amount']);

            foreach ($editorSummaries as $summary) {
                fputcsv($handle, [
                    $summary['name'],
                    $summary['email'],
                    $summary['shoot_count'],
                    $summary['service_count'],
                    number_format($summary['gross_total'], 2, '.', ''),
                    number_format($summary['unpaid_amount'] ?? 0, 2, '.', ''),
                ]);
            }

            fputcsv($handle, ['Total', '', $editorSummaries->sum('shoot_count'), $editorSummaries->sum('service_count'), number_format($editorSummaries->sum('gross_total'), 2, '.', ''), number_format($editorSummaries->sum('unpaid_amount'), 2, '.', '')]);
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

    public function send(Request $request)
    {
        $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'role' => 'nullable|string|in:all,photographer,salesRep,editor',
        ]);

        if ($request->filled('start') && $request->filled('end')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $end = Carbon::parse($request->input('end'))->endOfDay();
        } else {
            [$start, $end] = $this->service->lastCompletedWeekRange();
        }

        $role = $request->input('role', 'all');
        $groups = collect();

        if (in_array($role, ['all', 'photographer'], true)) {
            $groups = $groups->merge($this->service->buildPhotographerSummaries($start, $end)->all());
        }

        if (in_array($role, ['all', 'editor'], true)) {
            $groups = $groups->merge($this->service->buildEditorSummaries($start, $end)->all());
        }

        if (in_array($role, ['all', 'salesRep'], true)) {
            $groups = $groups->merge($this->service->buildSalesRepSummaries($start, $end)->all());
        }

        $sent = 0;
        foreach ($groups as $summary) {
            if (empty($summary['email'])) {
                continue;
            }

            $audience = match ($summary['role'] ?? null) {
                'salesRep' => 'sales rep',
                'editor' => 'editor',
                default => 'photographer',
            };

            $html = view('emails.payout-report', [
                'recipientName' => $summary['name'],
                'summary' => $summary,
                'rangeStart' => $start,
                'rangeEnd' => $end,
                'audience' => $audience,
            ])->render();

            $this->messagingService->sendEmail([
                'to' => $summary['email'],
                'subject' => sprintf('Weekly payout recap (%s - %s)', $start->format('M d'), $end->format('M d')),
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'PAYOUT_REPORT',
                'sender_name' => 'R/E Pro Photos',
            ]);
            $sent++;
        }

        return response()->json([
            'message' => 'Payout reports sent.',
            'sent_count' => $sent,
        ]);
    }
}
