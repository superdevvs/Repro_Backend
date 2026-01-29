<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Invoice;
use App\Models\Shoot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AccountingFlow
{
    /**
     * @return array{
     *   assistant_messages: array<int,array{content:string,metadata?:array}>,
     *   suggestions?: array<int,string>,
     *   actions?: array<int,array>
     * }
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        $step = $session->step ?? 'ask_period';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_period' => $this->askPeriod($session, $message, $data),
            'show_numbers' => $this->showNumbers($session, $message, $data),
            default => $this->askPeriod($session, $message, $data),
        };
    }

    private function showNumbers(AiChatSession $session, string $message, array $data): array
    {
        $period = $data['period'] ?? 'this_month';
        
        // Parse period from message if not in data
        if (empty($data['period']) && !empty(trim($message))) {
            $m = strtolower($message);
            $period = match(true) {
                str_contains($m, 'this month') => 'this_month',
                str_contains($m, 'last month') => 'last_month',
                str_contains($m, 'quarter') => 'this_quarter',
                str_contains($m, 'year') => 'this_year',
                str_contains($m, 'all') => 'all_time',
                default => 'this_month',
            };
            $data['period'] = $period;
        }
        
        $from = match($period) {
            'this_month' => now()->startOfMonth(),
            'last_month' => now()->subMonth()->startOfMonth(),
            'this_quarter' => now()->startOfQuarter(),
            'this_year' => now()->startOfYear(),
            default => Carbon::parse('2020-01-01'),
        };

        $to = match($period) {
            'this_month' => now()->endOfMonth(),
            'last_month' => now()->subMonth()->endOfMonth(),
            'this_quarter' => now()->endOfQuarter(),
            'this_year' => now()->endOfYear(),
            default => now(),
        };

        // Get shoots for the user
        $shoots = Shoot::where(function ($query) use ($session) {
            $query->where('client_id', $session->user_id)
                  ->orWhere('rep_id', $session->user_id);
        })
        ->whereBetween('created_at', [$from, $to])
        ->get();

        // Get invoices
        $invoices = Invoice::whereBetween('created_at', [$from, $to])
            ->where(function ($query) use ($session) {
                $query->where('user_id', $session->user_id)
                      ->orWhereHas('shoots', function ($q) use ($session) {
                          $q->where('client_id', $session->user_id)
                            ->orWhere('rep_id', $session->user_id);
                      });
            })
            ->get();

        // Revenue calculations
        $totalRevenue = $shoots->sum('total_quote');
        $paidRevenue = $shoots->where('payment_status', 'paid')->sum('total_quote');
        $pendingRevenue = $shoots->where('payment_status', 'unpaid')->sum('total_quote');
        
        // Invoice calculations
        $totalInvoices = $invoices->sum('total');
        $paidInvoices = $invoices->where('is_paid', true)->sum('total');
        $outstandingInvoices = $invoices->where('is_paid', false)->sum('total');
        
        // Last payment date
        $lastPayment = $shoots->where('payment_status', 'paid')
            ->whereNotNull('updated_at')
            ->sortByDesc('updated_at')
            ->first();
        $lastPaymentDate = $lastPayment ? $lastPayment->updated_at->format('M d, Y') : 'None';

        $periodLabel = match($period) {
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'this_quarter' => 'This Quarter',
            'this_year' => 'This Year',
            default => 'All Time',
        };

        $summary = "💰 Accounting Summary - **{$periodLabel}**\n\n";
        $summary .= "**Revenue:**\n";
        $summary .= "• Total: $" . number_format($totalRevenue, 2) . "\n";
        $summary .= "• Paid: $" . number_format($paidRevenue, 2) . "\n";
        $summary .= "• Pending: $" . number_format($pendingRevenue, 2) . "\n\n";
        $summary .= "**Invoices:**\n";
        $summary .= "• Total: $" . number_format($totalInvoices, 2) . "\n";
        $summary .= "• Paid: $" . number_format($paidInvoices, 2) . "\n";
        $summary .= "• Outstanding: $" . number_format($outstandingInvoices, 2) . "\n\n";
        $summary .= "**Activity:**\n";
        $summary .= "• Total Shoots: " . $shoots->count() . "\n";
        $summary .= "• Last Payment: {$lastPaymentDate}\n";

        $this->setStepAndData($session, 'show_numbers', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => $summary,
                'metadata' => ['step' => 'show_numbers', 'period' => $period],
            ]],
            'suggestions' => [
                'View this month',
                'View last month',
                'View this quarter',
                'Check client stats',
            ],
            'meta' => [
                'period' => $period,
                'stats' => [
                    'total_revenue' => $totalRevenue,
                    'paid_revenue' => $paidRevenue,
                    'pending_revenue' => $pendingRevenue,
                    'outstanding_invoices' => $outstandingInvoices,
                    'last_payment_date' => $lastPaymentDate,
                ],
            ],
        ];
    }

    private function askPeriod(AiChatSession $session, string $message, array $data): array
    {
        // Parse period from message if provided
        if (!empty(trim($message)) && !str_contains(strtolower($message), 'accounting') && !str_contains(strtolower($message), 'summary')) {
            $m = strtolower($message);
            $period = match(true) {
                str_contains($m, 'this month') => 'this_month',
                str_contains($m, 'last month') => 'last_month',
                str_contains($m, 'quarter') => 'this_quarter',
                str_contains($m, 'year') => 'this_year',
                str_contains($m, 'all') => 'all_time',
                default => null,
            };
            
            if ($period) {
                $data['period'] = $period;
                $this->setStepAndData($session, 'show_numbers', $data);
                return $this->showNumbers($session, $message, $data);
            }
        }

        // If period already in data, show numbers
        if (!empty($data['period'])) {
            $this->setStepAndData($session, 'show_numbers', $data);
            return $this->showNumbers($session, $message, $data);
        }

        // Otherwise, ask for period
        $this->setStepAndData($session, 'ask_period', $data);
        return [
            'assistant_messages' => [[
                'content' => "What period would you like to see accounting for?",
                'metadata' => ['step' => 'ask_period'],
            ]],
            'suggestions' => [
                'This month',
                'Last month',
                'This quarter',
                'This year',
                'All time',
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

