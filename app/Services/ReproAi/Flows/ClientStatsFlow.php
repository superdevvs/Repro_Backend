<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ClientStatsFlow
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
        $step = $session->step ?? 'ask_client';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_client' => $this->askClient($session, $message, $data),
            'show_summary' => $this->showSummary($session, $message, $data),
            default => $this->askClient($session, $message, $data),
        };
    }

    private function askClient(AiChatSession $session, string $message, array $data): array
    {
        // Check if client_id already set
        if (!empty($data['client_id'])) {
            $this->setStepAndData($session, 'show_summary', $data);
            $session->save();
            return $this->showSummary($session, $message, $data);
        }

        // Try to match client from message
        $messageLower = strtolower(trim($message));
        
        // Handle "my stats" or "my" - show current user's stats
        if ($messageLower === 'my stats' || $messageLower === 'my' || $messageLower === 'me') {
            $data['client_id'] = $session->user_id;
            $this->setStepAndData($session, 'show_summary', $data);
            $session->save();
            return $this->showSummary($session, $message, $data);
        }

        // Try to match a specific client by name
        if (!empty(trim($message)) && !str_contains($messageLower, 'stats') && !str_contains($messageLower, 'client')) {
            $recentClients = User::where('role', 'client')
                ->has('shoots')
                ->get(['id', 'name', 'email']);
            
            foreach ($recentClients as $client) {
                $clientNameLower = strtolower($client->name);
                // Match by name (with or without shoot count suffix)
                if (str_contains($messageLower, $clientNameLower) || 
                    str_contains($clientNameLower, $messageLower) ||
                    preg_match('/^' . preg_quote($clientNameLower, '/') . '\s*\(/i', $messageLower)) {
                    $data['client_id'] = $client->id;
                    $data['client_name'] = $client->name;
                    $this->setStepAndData($session, 'show_summary', $data);
                    $session->save();
                    return $this->showSummary($session, $message, $data);
                }
            }
        }

        // First time asking - show client list
        $this->setStepAndData($session, 'ask_client', $data);
        $session->save();

        $recentClients = User::where('role', 'client')
            ->has('shoots')
            ->withCount('shoots')
            ->orderBy('shoots_count', 'desc')
            ->limit(10)
            ->get(['id', 'name', 'email']);

        $suggestions = ['My stats'];
        foreach ($recentClients as $client) {
            $suggestions[] = "{$client->name} ({$client->shoots_count} shoots)";
        }

        return [
            'assistant_messages' => [[
                'content' => "Which client would you like to see stats for?",
                'metadata' => ['step' => 'ask_client'],
            ]],
            'suggestions' => $suggestions,
        ];
    }

    private function showSummary(AiChatSession $session, string $message, array $data): array
    {
        $clientId = $data['client_id'] ?? $session->user_id;
        $client = User::find($clientId);

        if (!$client) {
            return [
                'assistant_messages' => [[
                    'content' => "I couldn't find that client.",
                    'metadata' => ['step' => 'ask_client'],
                ]],
                'suggestions' => ['Show client list'],
            ];
        }

        // Get all shoots for the client (with services loaded)
        $allShoots = Shoot::where('client_id', $clientId)
            ->with('services')
            ->get();
        
        // Last 30 days
        $shoots30Days = $allShoots->filter(function ($shoot) {
            return $shoot->created_at->isAfter(now()->subDays(30));
        });
        
        // Last 90 days
        $shoots90Days = $allShoots->filter(function ($shoot) {
            return $shoot->created_at->isAfter(now()->subDays(90));
        });

        // Calculate turnaround time (average days from scheduled to completed)
        $completedShoots = $allShoots->where('status', 'completed')
            ->whereNotNull('scheduled_at')
            ->whereNotNull('completed_at');
        
        $avgTurnaround = 0;
        if ($completedShoots->isNotEmpty()) {
            $totalDays = $completedShoots->sum(function ($shoot) {
                return $shoot->scheduled_at->diffInDays($shoot->completed_at);
            });
            $avgTurnaround = round($totalDays / $completedShoots->count(), 1);
        }

        // Most used services
        $serviceUsage = [];
        foreach ($allShoots as $shoot) {
            foreach ($shoot->services as $service) {
                $serviceUsage[$service->name] = ($serviceUsage[$service->name] ?? 0) + 1;
            }
        }
        arsort($serviceUsage);
        $topService = !empty($serviceUsage) ? array_key_first($serviceUsage) : 'N/A';

        // Revenue calculations
        $totalRevenue = $allShoots->sum('total_quote');
        $revenue30Days = $shoots30Days->sum('total_quote');
        $revenue90Days = $shoots90Days->sum('total_quote');
        $pendingPayments = $allShoots->where('payment_status', 'unpaid')->sum('total_quote');

        $summary = "📊 Client Stats for **{$client->name}**\n\n";
        $summary .= "**Last 30 Days:**\n";
        $summary .= "• Shoots: {$shoots30Days->count()}\n";
        $summary .= "• Revenue: $" . number_format($revenue30Days, 2) . "\n\n";
        $summary .= "**Last 90 Days:**\n";
        $summary .= "• Shoots: {$shoots90Days->count()}\n";
        $summary .= "• Revenue: $" . number_format($revenue90Days, 2) . "\n\n";
        $summary .= "**All Time:**\n";
        $summary .= "• Total Shoots: {$allShoots->count()}\n";
        $summary .= "• Completed: {$allShoots->where('status', 'completed')->count()}\n";
        $summary .= "• Total Revenue: $" . number_format($totalRevenue, 2) . "\n";
        $summary .= "• Pending Payments: $" . number_format($pendingPayments, 2) . "\n";
        $summary .= "• Avg Turnaround: {$avgTurnaround} days\n";
        $summary .= "• Most Used Service: {$topService}\n";

        $this->setStepAndData($session, 'show_summary', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => $summary,
                'metadata' => ['step' => 'show_summary', 'client_id' => $clientId],
            ]],
            'suggestions' => [
                'View another client',
                'Book a shoot for this client',
                'See accounting summary',
            ],
            'meta' => [
                'client_id' => $clientId,
                'stats' => [
                    'total_shoots' => $allShoots->count(),
                    'shoots_30_days' => $shoots30Days->count(),
                    'shoots_90_days' => $shoots90Days->count(),
                    'total_revenue' => $totalRevenue,
                    'revenue_30_days' => $revenue30Days,
                    'revenue_90_days' => $revenue90Days,
                    'avg_turnaround' => $avgTurnaround,
                    'top_service' => $topService,
                ],
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

