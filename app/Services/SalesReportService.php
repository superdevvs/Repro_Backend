<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\User;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    /**
     * Generate weekly sales report for a sales rep
     */
    public function generateWeeklyReportForSalesRep(User $salesRep, Carbon $startDate, Carbon $endDate): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();

        // Get all shoots assigned to this sales rep in the period
        $shoots = Shoot::with(['client', 'photographer', 'payments'])
            ->where('rep_id', $salesRep->id)
            ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        // Calculate statistics
        $totalShoots = $shoots->count();
        $completedShoots = $shoots->where('workflow_status', Shoot::WORKFLOW_COMPLETED)->count();
        $totalRevenue = $shoots->sum('total_quote');
        $totalPaid = $shoots->flatMap(fn($shoot) => $shoot->payments)
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');
        $outstandingBalance = $totalRevenue - $totalPaid;

        // Group by client
        $clients = $shoots->groupBy('client_id')->map(function ($clientShoots, $clientId) {
            $client = $clientShoots->first()->client;
            return [
                'client_id' => $clientId,
                'client_name' => $client ? $client->name : 'Unknown',
                'client_email' => $client ? $client->email : null,
                'shoot_count' => $clientShoots->count(),
                'total_revenue' => $clientShoots->sum('total_quote'),
                'total_paid' => $clientShoots->flatMap(fn($s) => $s->payments)
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->sum('amount'),
            ];
        })->values();

        // Group by photographer
        $photographers = $shoots->whereNotNull('photographer_id')
            ->groupBy('photographer_id')
            ->map(function ($photographerShoots, $photographerId) {
                $photographer = $photographerShoots->first()->photographer;
                return [
                    'photographer_id' => $photographerId,
                    'photographer_name' => $photographer ? $photographer->name : 'Unknown',
                    'shoot_count' => $photographerShoots->count(),
                ];
            })->values();

        // Top performing shoots by revenue
        $topShoots = $shoots->sortByDesc('total_quote')
            ->take(10)
            ->map(function ($shoot) {
                return [
                    'shoot_id' => $shoot->id,
                    'client_name' => $shoot->client ? $shoot->client->name : 'Unknown',
                    'scheduled_date' => $shoot->scheduled_date ? $shoot->scheduled_date->format('Y-m-d') : null,
                    'total_quote' => $shoot->total_quote,
                    'workflow_status' => $shoot->workflow_status,
                ];
            })
            ->values();

        return [
            'sales_rep' => [
                'id' => $salesRep->id,
                'name' => $salesRep->name,
                'email' => $salesRep->email,
            ],
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'week_number' => $startDate->week,
                'year' => $startDate->year,
            ],
            'summary' => [
                'total_shoots' => $totalShoots,
                'completed_shoots' => $completedShoots,
                'completion_rate' => $totalShoots > 0 ? round(($completedShoots / $totalShoots) * 100, 2) : 0,
                'total_revenue' => round($totalRevenue, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding_balance' => round($outstandingBalance, 2),
                'average_shoot_value' => $totalShoots > 0 ? round($totalRevenue / $totalShoots, 2) : 0,
            ],
            'clients' => $clients,
            'photographers' => $photographers,
            'top_shoots' => $topShoots,
        ];
    }

    /**
     * Generate weekly sales report for all sales reps
     */
    public function generateWeeklyReportsForAllSalesReps(Carbon $startDate, Carbon $endDate): Collection
    {
        $salesReps = User::where('role', 'salesRep')->get();

        return $salesReps->map(function ($salesRep) use ($startDate, $endDate) {
            return $this->generateWeeklyReportForSalesRep($salesRep, $startDate, $endDate);
        });
    }

    /**
     * Get last completed week dates
     */
    public function getLastCompletedWeek(): array
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return [$start, $end];
    }
}


