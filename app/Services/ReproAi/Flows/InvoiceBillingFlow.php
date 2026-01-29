<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InvoiceBillingFlow
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
        $step = $session->step ?? 'ask_action';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_action' => $this->askAction($session, $message, $data),
            'create_invoice' => $this->handleCreateInvoice($session, $message, $data),
            'send_invoice' => $this->handleSendInvoice($session, $message, $data),
            'outstanding_invoices' => $this->handleOutstandingInvoices($session, $message, $data),
            'apply_discount' => $this->handleApplyDiscount($session, $message, $data),
            default => $this->askAction($session, $message, $data),
        };
    }

    private function askAction(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Detect specific action from message
        if (str_contains($messageLower, 'create') && str_contains($messageLower, 'invoice')) {
            $this->setStepAndData($session, 'create_invoice', $data);
            $session->save();
            return $this->handleCreateInvoice($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'send') && str_contains($messageLower, 'invoice')) {
            $this->setStepAndData($session, 'send_invoice', $data);
            $session->save();
            return $this->handleSendInvoice($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'outstanding') || str_contains($messageLower, 'unpaid') || str_contains($messageLower, 'overdue')) {
            $this->setStepAndData($session, 'outstanding_invoices', $data);
            $session->save();
            return $this->handleOutstandingInvoices($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'discount') || str_contains($messageLower, 'promo')) {
            $this->setStepAndData($session, 'apply_discount', $data);
            $session->save();
            return $this->handleApplyDiscount($session, $message, $data);
        }

        // Show action menu
        $this->setStepAndData($session, 'ask_action', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => "📄 **Invoice & Billing**\n\nWhat would you like to do?",
                'metadata' => ['step' => 'ask_action'],
            ]],
            'suggestions' => [
                'Create invoice for a shoot',
                'Send invoice to client',
                'View outstanding invoices',
                'Apply discount to booking',
            ],
        ];
    }

    private function handleCreateInvoice(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Step 1: Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get shoots without invoices
            $shootsWithoutInvoice = Shoot::whereDoesntHave('invoices')
                ->where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($shootsWithoutInvoice as $shoot) {
                if (preg_match('/#?(\d+)/', $message, $matches)) {
                    if ((int)$matches[1] === $shoot->id) {
                        $data['shoot_id'] = $shoot->id;
                        break;
                    }
                }
                if (str_contains($messageLower, strtolower($shoot->address))) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
            }
            
            if (empty($data['shoot_id'])) {
                if ($shootsWithoutInvoice->isEmpty()) {
                    // Check for any completed shoots
                    $completedShoots = Shoot::where('status', 'completed')
                        ->orderBy('completed_at', 'desc')
                        ->limit(10)
                        ->get();
                    
                    if ($completedShoots->isEmpty()) {
                        return [
                            'assistant_messages' => [[
                                'content' => "📋 No completed shoots found to create invoices for.",
                                'metadata' => ['step' => 'create_invoice'],
                            ]],
                            'suggestions' => [
                                'View outstanding invoices',
                                'Book a new shoot',
                            ],
                        ];
                    }
                    
                    $suggestions = [];
                    foreach ($completedShoots as $shoot) {
                        $dateStr = $shoot->completed_at ? $shoot->completed_at->format('M d') : 'N/A';
                        $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$dateStr})";
                    }
                    
                    $this->setStepAndData($session, 'create_invoice', $data);
                    $session->save();
                    
                    return [
                        'assistant_messages' => [[
                            'content' => "📋 Which shoot would you like to create an invoice for?",
                            'metadata' => ['step' => 'create_invoice'],
                        ]],
                        'suggestions' => $suggestions,
                    ];
                }
                
                $suggestions = [];
                foreach ($shootsWithoutInvoice as $shoot) {
                    $dateStr = $shoot->completed_at ? $shoot->completed_at->format('M d') : 'N/A';
                    $amount = number_format($shoot->total_quote ?? 0, 2);
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} (${$amount})";
                }
                
                $this->setStepAndData($session, 'create_invoice', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📋 These completed shoots don't have invoices yet. Which one?",
                        'metadata' => ['step' => 'create_invoice'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Step 2: Create the invoice
        $shoot = Shoot::with(['client', 'services'])->find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that shoot.",
                    'metadata' => ['step' => 'create_invoice'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Generate invoice number
        $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . str_pad($shoot->id, 4, '0', STR_PAD_LEFT);
        
        // Create the invoice
        $invoice = Invoice::create([
            'client_id' => $shoot->client_id,
            'shoot_id' => $shoot->id,
            'invoice_number' => $invoiceNumber,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $shoot->base_quote ?? $shoot->total_quote ?? 0,
            'tax' => $shoot->tax_amount ?? 0,
            'total' => $shoot->total_quote ?? 0,
            'status' => Invoice::STATUS_DRAFT,
        ]);
        
        // Add line items from services
        foreach ($shoot->services as $service) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $service->name,
                'quantity' => $service->pivot->quantity ?? 1,
                'unit_price' => $service->pivot->price ?? $service->price ?? 0,
                'total_amount' => ($service->pivot->quantity ?? 1) * ($service->pivot->price ?? $service->price ?? 0),
                'type' => InvoiceItem::TYPE_CHARGE ?? 'charge',
            ]);
        }
        
        $clientName = $shoot->client?->name ?? 'Client';
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Invoice Created!**\n\n" .
                    "📄 **Invoice #**: {$invoiceNumber}\n" .
                    "👤 **Client**: {$clientName}\n" .
                    "📍 **Property**: {$shoot->address}\n" .
                    "💰 **Amount**: $" . number_format($invoice->total, 2) . "\n" .
                    "📅 **Due Date**: " . $invoice->due_date->format('M d, Y') . "\n\n" .
                    "Would you like to send this invoice to the client?",
                'metadata' => [
                    'step' => 'done',
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoiceNumber,
                ],
            ]],
            'suggestions' => [
                'Yes, send to client',
                'Create another invoice',
                'View outstanding invoices',
            ],
            'actions' => [
                [
                    'type' => 'view_invoice',
                    'invoice_id' => $invoice->id,
                ],
            ],
        ];
    }

    private function handleSendInvoice(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Check if user wants to send the just-created invoice
        if (str_contains($messageLower, 'yes') && !empty($session->messages)) {
            // Get the last invoice from metadata
            $lastMessages = $session->messages()->orderBy('created_at', 'desc')->limit(5)->get();
            foreach ($lastMessages as $msg) {
                $metadata = $msg->metadata ?? [];
                if (!empty($metadata['invoice_id'])) {
                    $data['invoice_id'] = $metadata['invoice_id'];
                    break;
                }
            }
        }
        
        // Select invoice if not selected
        if (empty($data['invoice_id'])) {
            // Get unsent invoices
            $unsentInvoices = Invoice::where('status', Invoice::STATUS_DRAFT)
                ->with(['client', 'shoot'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($unsentInvoices as $invoice) {
                if (str_contains($messageLower, strtolower($invoice->invoice_number ?? ''))) {
                    $data['invoice_id'] = $invoice->id;
                    break;
                }
                if (preg_match('/#?(\d+)/', $message, $matches)) {
                    if ((int)$matches[1] === $invoice->id) {
                        $data['invoice_id'] = $invoice->id;
                        break;
                    }
                }
            }
            
            if (empty($data['invoice_id'])) {
                if ($unsentInvoices->isEmpty()) {
                    return [
                        'assistant_messages' => [[
                            'content' => "📋 No unsent invoices found. All invoices have been sent!",
                            'metadata' => ['step' => 'send_invoice'],
                        ]],
                        'suggestions' => [
                            'Create a new invoice',
                            'View outstanding invoices',
                        ],
                    ];
                }
                
                $suggestions = [];
                foreach ($unsentInvoices as $invoice) {
                    $clientName = $invoice->client?->name ?? 'Unknown';
                    $amount = number_format($invoice->total ?? 0, 2);
                    $suggestions[] = "{$invoice->invoice_number} - {$clientName} (${$amount})";
                }
                
                $this->setStepAndData($session, 'send_invoice', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📤 Which invoice would you like to send?",
                        'metadata' => ['step' => 'send_invoice'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Send the invoice
        $invoice = Invoice::with(['client', 'shoot'])->find($data['invoice_id']);
        
        if (!$invoice) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that invoice.",
                    'metadata' => ['step' => 'send_invoice'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Mark as sent
        $invoice->markSent();
        
        // Try to send email notification
        $emailSent = false;
        $clientEmail = $invoice->client?->email;
        
        if ($clientEmail) {
            try {
                $messagingService = app(MessagingService::class);
                $paymentLink = config('app.url') . "/pay/invoice/{$invoice->id}";
                
                $messagingService->sendEmail([
                    'to' => $clientEmail,
                    'subject' => "Invoice {$invoice->invoice_number} from REPRO-HQ",
                    'body_html' => "<h2>Invoice {$invoice->invoice_number}</h2>" .
                        "<p>Amount Due: $" . number_format($invoice->total, 2) . "</p>" .
                        "<p>Due Date: " . $invoice->due_date->format('M d, Y') . "</p>" .
                        "<p><a href='{$paymentLink}'>Pay Now</a></p>",
                    'body_text' => "Invoice {$invoice->invoice_number}\nAmount: $" . number_format($invoice->total, 2),
                    'related_invoice_id' => $invoice->id,
                ]);
                $emailSent = true;
            } catch (\Exception $e) {
                \Log::warning('Failed to send invoice email', ['error' => $e->getMessage()]);
            }
        }
        
        $clientName = $invoice->client?->name ?? 'Client';
        $emailStatus = $emailSent ? "📧 Email sent to {$clientEmail}" : "⚠️ Email not sent (no email on file)";
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Invoice Sent!**\n\n" .
                    "📄 **Invoice #**: {$invoice->invoice_number}\n" .
                    "👤 **Client**: {$clientName}\n" .
                    "💰 **Amount**: $" . number_format($invoice->total, 2) . "\n" .
                    "{$emailStatus}",
                'metadata' => [
                    'step' => 'done',
                    'invoice_id' => $invoice->id,
                    'email_sent' => $emailSent,
                ],
            ]],
            'suggestions' => [
                'Send another invoice',
                'View outstanding invoices',
                'Create a new invoice',
            ],
        ];
    }

    private function handleOutstandingInvoices(AiChatSession $session, string $message, array $data): array
    {
        // Get outstanding invoices
        $outstanding = Invoice::where('status', '!=', Invoice::STATUS_PAID)
            ->with(['client', 'shoot'])
            ->orderBy('due_date', 'asc')
            ->get();
        
        if ($outstanding->isEmpty()) {
            $this->setStepAndData($session, null, []);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "🎉 **No outstanding invoices!** All invoices have been paid.",
                    'metadata' => ['step' => 'outstanding_invoices'],
                ]],
                'suggestions' => [
                    'Create a new invoice',
                    'View accounting summary',
                ],
            ];
        }
        
        $totalOutstanding = $outstanding->sum('total');
        $overdueCount = $outstanding->filter(fn($inv) => $inv->isPastDue())->count();
        
        $content = "📋 **Outstanding Invoices**\n\n";
        $content .= "**Summary:**\n";
        $content .= "• Total Outstanding: $" . number_format($totalOutstanding, 2) . "\n";
        $content .= "• Invoices: {$outstanding->count()}\n";
        $content .= "• Overdue: {$overdueCount}\n\n";
        
        $content .= "**Details:**\n";
        foreach ($outstanding->take(10) as $invoice) {
            $clientName = $invoice->client?->name ?? 'Unknown';
            $status = $invoice->isPastDue() ? '🔴 OVERDUE' : ($invoice->status === 'sent' ? '🟡 Sent' : '⚪ Draft');
            $dueDate = $invoice->due_date ? $invoice->due_date->format('M d') : 'N/A';
            $content .= "• {$invoice->invoice_number} - {$clientName} - $" . number_format($invoice->total, 2) . " (Due: {$dueDate}) {$status}\n";
        }
        
        if ($outstanding->count() > 10) {
            $content .= "\n... and " . ($outstanding->count() - 10) . " more";
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'step' => 'outstanding_invoices',
                    'total_outstanding' => $totalOutstanding,
                    'count' => $outstanding->count(),
                    'overdue_count' => $overdueCount,
                ],
            ]],
            'suggestions' => [
                'Send payment reminder',
                'Create a new invoice',
                'View accounting summary',
            ],
        ];
    }

    private function handleApplyDiscount(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select shoot/invoice if not selected
        if (empty($data['shoot_id']) && empty($data['invoice_id'])) {
            // Get recent shoots
            $recentShoots = Shoot::whereNotIn('status', ['cancelled'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            
            foreach ($recentShoots as $shoot) {
                if (preg_match('/#?(\d+)/', $message, $matches)) {
                    if ((int)$matches[1] === $shoot->id) {
                        $data['shoot_id'] = $shoot->id;
                        break;
                    }
                }
                if (str_contains($messageLower, strtolower($shoot->address))) {
                    $data['shoot_id'] = $shoot->id;
                    break;
                }
            }
            
            if (empty($data['shoot_id'])) {
                $suggestions = [];
                foreach ($recentShoots as $shoot) {
                    $amount = number_format($shoot->total_quote ?? 0, 2);
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} (${$amount})";
                }
                
                $this->setStepAndData($session, 'apply_discount', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "🏷️ Which booking would you like to apply a discount to?",
                        'metadata' => ['step' => 'apply_discount'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Get discount amount if not provided
        if (empty($data['discount_amount']) && empty($data['discount_percent'])) {
            // Try to parse from message
            if (preg_match('/(\d+)%/', $message, $matches)) {
                $data['discount_percent'] = (int)$matches[1];
            } elseif (preg_match('/\$?(\d+(?:\.\d{2})?)/', $message, $matches)) {
                $data['discount_amount'] = (float)$matches[1];
            } else {
                $this->setStepAndData($session, 'apply_discount', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "💰 How much discount would you like to apply?\n\nYou can enter a percentage (e.g., 10%) or a fixed amount (e.g., $50).",
                        'metadata' => ['step' => 'apply_discount'],
                    ]],
                    'suggestions' => [
                        '10%',
                        '15%',
                        '20%',
                        '$50',
                        '$100',
                    ],
                ];
            }
        }
        
        // Apply the discount
        $shoot = Shoot::find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that booking.",
                    'metadata' => ['step' => 'apply_discount'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        $originalTotal = $shoot->total_quote ?? 0;
        $discountAmount = 0;
        
        if (!empty($data['discount_percent'])) {
            $discountAmount = $originalTotal * ($data['discount_percent'] / 100);
            $discountLabel = "{$data['discount_percent']}%";
        } else {
            $discountAmount = $data['discount_amount'];
            $discountLabel = "$" . number_format($discountAmount, 2);
        }
        
        $newTotal = max($originalTotal - $discountAmount, 0);
        
        // Update shoot
        $shoot->total_quote = $newTotal;
        $shoot->save();
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Discount Applied!**\n\n" .
                    "📍 **Shoot**: #{$shoot->id} - {$shoot->address}\n" .
                    "🏷️ **Discount**: {$discountLabel} (-$" . number_format($discountAmount, 2) . ")\n" .
                    "💰 **Original**: $" . number_format($originalTotal, 2) . "\n" .
                    "💵 **New Total**: $" . number_format($newTotal, 2),
                'metadata' => [
                    'step' => 'done',
                    'shoot_id' => $shoot->id,
                    'discount_amount' => $discountAmount,
                    'new_total' => $newTotal,
                ],
            ]],
            'suggestions' => [
                'Apply another discount',
                'Create invoice',
                'View booking',
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
