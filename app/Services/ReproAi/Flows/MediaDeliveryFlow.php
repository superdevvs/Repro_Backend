<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaDeliveryFlow
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
            'delivery_status' => $this->handleDeliveryStatus($session, $message, $data),
            'share_gallery' => $this->handleShareGallery($session, $message, $data),
            'request_reshoot' => $this->handleRequestReshoot($session, $message, $data),
            'download_all' => $this->handleDownloadAll($session, $message, $data),
            default => $this->askAction($session, $message, $data),
        };
    }

    private function askAction(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Detect specific action from message
        if (str_contains($messageLower, 'status') || str_contains($messageLower, 'delivery') || str_contains($messageLower, 'ready')) {
            $this->setStepAndData($session, 'delivery_status', $data);
            $session->save();
            return $this->handleDeliveryStatus($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'share') || str_contains($messageLower, 'gallery') || str_contains($messageLower, 'link')) {
            $this->setStepAndData($session, 'share_gallery', $data);
            $session->save();
            return $this->handleShareGallery($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'reshoot') || str_contains($messageLower, 're-shoot') || str_contains($messageLower, 'retake')) {
            $this->setStepAndData($session, 'request_reshoot', $data);
            $session->save();
            return $this->handleRequestReshoot($session, $message, $data);
        }
        
        if (str_contains($messageLower, 'download') || str_contains($messageLower, 'zip') || str_contains($messageLower, 'all photos')) {
            $this->setStepAndData($session, 'download_all', $data);
            $session->save();
            return $this->handleDownloadAll($session, $message, $data);
        }

        // Show action menu
        $this->setStepAndData($session, 'ask_action', $data);
        $session->save();

        return [
            'assistant_messages' => [[
                'content' => "📸 **Media Delivery**\n\nWhat would you like to do?",
                'metadata' => ['step' => 'ask_action'],
            ]],
            'suggestions' => [
                'Check delivery status',
                'Share gallery with client',
                'Request reshoot',
                'Download all photos',
            ],
        ];
    }

    private function handleDeliveryStatus(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get recent shoots
            $recentShoots = Shoot::whereIn('status', ['completed', 'editing', 'ready'])
                ->with('client')
                ->orderBy('scheduled_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
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
                    $clientName = $shoot->client?->name ?? 'Unknown';
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$clientName})";
                }
                
                $this->setStepAndData($session, 'delivery_status', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📋 Which shoot would you like to check delivery status for?",
                        'metadata' => ['step' => 'delivery_status'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $shoot = Shoot::with(['client', 'files'])->find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that shoot.",
                    'metadata' => ['step' => 'delivery_status'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Count files
        $totalFiles = $shoot->files->count();
        $completedFiles = $shoot->files->where('status', 'completed')->count();
        $pendingFiles = $shoot->files->where('status', 'pending')->count();
        
        // Determine delivery status
        $deliveryStatus = match(true) {
            $shoot->workflow_status === 'delivered' => '✅ Delivered',
            $shoot->workflow_status === 'ready' => '📦 Ready for Delivery',
            $shoot->workflow_status === 'editing' => '🎨 In Editing',
            $shoot->status === 'completed' && $completedFiles > 0 => '📦 Ready for Delivery',
            $shoot->status === 'completed' => '⏳ Awaiting Photos',
            default => '📸 Shoot Scheduled',
        };
        
        $clientName = $shoot->client?->name ?? 'Unknown';
        
        $content = "📊 **Delivery Status for #{$shoot->id}**\n\n";
        $content .= "📍 **Property**: {$shoot->address}\n";
        $content .= "👤 **Client**: {$clientName}\n";
        $content .= "📅 **Shot Date**: " . ($shoot->scheduled_at ? $shoot->scheduled_at->format('M d, Y') : 'TBD') . "\n\n";
        $content .= "**Status**: {$deliveryStatus}\n\n";
        
        if ($totalFiles > 0) {
            $content .= "**Files:**\n";
            $content .= "• Total: {$totalFiles}\n";
            $content .= "• Completed: {$completedFiles}\n";
            $content .= "• Pending: {$pendingFiles}\n";
        } else {
            $content .= "**Files:** No files uploaded yet\n";
        }
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        $suggestions = ['Share gallery with client', 'Check another shoot'];
        if ($completedFiles > 0) {
            array_unshift($suggestions, 'Download all photos');
        }
        
        return [
            'assistant_messages' => [[
                'content' => $content,
                'metadata' => [
                    'step' => 'delivery_status',
                    'shoot_id' => $shoot->id,
                    'total_files' => $totalFiles,
                    'completed_files' => $completedFiles,
                ],
            ]],
            'suggestions' => $suggestions,
        ];
    }

    private function handleShareGallery(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get shoots with completed files
            $shootsWithFiles = Shoot::whereIn('status', ['completed', 'ready', 'delivered'])
                ->whereHas('files', function ($query) {
                    $query->where('status', 'completed');
                })
                ->with('client')
                ->orderBy('scheduled_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($shootsWithFiles as $shoot) {
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
                if ($shootsWithFiles->isEmpty()) {
                    return [
                        'assistant_messages' => [[
                            'content' => "📋 No shoots with completed photos found.",
                            'metadata' => ['step' => 'share_gallery'],
                        ]],
                        'suggestions' => [
                            'Check delivery status',
                            'Book a new shoot',
                        ],
                    ];
                }
                
                $suggestions = [];
                foreach ($shootsWithFiles as $shoot) {
                    $clientName = $shoot->client?->name ?? 'Unknown';
                    $fileCount = $shoot->files->where('status', 'completed')->count();
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$fileCount} photos)";
                }
                
                $this->setStepAndData($session, 'share_gallery', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "🔗 Which shoot's gallery would you like to share?",
                        'metadata' => ['step' => 'share_gallery'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $shoot = Shoot::with(['client', 'files'])->find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that shoot.",
                    'metadata' => ['step' => 'share_gallery'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Generate shareable gallery link
        $galleryToken = Str::random(32);
        $galleryLink = config('app.url') . "/gallery/{$shoot->id}?token={$galleryToken}";
        
        // Store token in shoot metadata if possible
        $metadata = $shoot->metadata ?? [];
        $metadata['gallery_token'] = $galleryToken;
        $metadata['gallery_created_at'] = now()->toIso8601String();
        $shoot->metadata = $metadata;
        $shoot->save();
        
        $clientName = $shoot->client?->name ?? 'Unknown';
        $clientEmail = $shoot->client?->email;
        $fileCount = $shoot->files->where('status', 'completed')->count();
        
        // Offer to send email
        $emailOption = $clientEmail ? "\n\nWould you like me to email this link to **{$clientName}** at {$clientEmail}?" : "";
        
        $this->setStepAndData($session, null, ['shoot_id' => $shoot->id, 'gallery_link' => $galleryLink]);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "🔗 **Gallery Link Created!**\n\n" .
                    "📍 **Property**: {$shoot->address}\n" .
                    "👤 **Client**: {$clientName}\n" .
                    "📸 **Photos**: {$fileCount}\n\n" .
                    "**Shareable Link:**\n{$galleryLink}" .
                    $emailOption,
                'metadata' => [
                    'step' => 'share_gallery',
                    'shoot_id' => $shoot->id,
                    'gallery_link' => $galleryLink,
                    'file_count' => $fileCount,
                ],
            ]],
            'suggestions' => $clientEmail 
                ? ['Yes, email to client', 'Copy link', 'Share another gallery']
                : ['Copy link', 'Share another gallery'],
        ];
    }

    private function handleRequestReshoot(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get completed shoots
            $completedShoots = Shoot::whereIn('status', ['completed', 'ready', 'delivered'])
                ->with('client')
                ->orderBy('scheduled_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($completedShoots as $shoot) {
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
                foreach ($completedShoots as $shoot) {
                    $clientName = $shoot->client?->name ?? 'Unknown';
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$clientName})";
                }
                
                $this->setStepAndData($session, 'request_reshoot', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📸 Which shoot needs a reshoot?",
                        'metadata' => ['step' => 'request_reshoot'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        // Get reason if not provided
        if (empty($data['reshoot_reason'])) {
            // Common reasons
            $commonReasons = [
                'weather' => str_contains($messageLower, 'weather') || str_contains($messageLower, 'rain') || str_contains($messageLower, 'cloudy'),
                'staging' => str_contains($messageLower, 'stag') || str_contains($messageLower, 'furniture'),
                'quality' => str_contains($messageLower, 'quality') || str_contains($messageLower, 'blur'),
                'missing' => str_contains($messageLower, 'missing') || str_contains($messageLower, 'forgot'),
            ];
            
            foreach ($commonReasons as $reason => $matched) {
                if ($matched) {
                    $data['reshoot_reason'] = $reason;
                    break;
                }
            }
            
            if (empty($data['reshoot_reason']) && !empty(trim($message)) && !str_contains($messageLower, 'reshoot')) {
                $data['reshoot_reason'] = $message;
            }
            
            if (empty($data['reshoot_reason'])) {
                $this->setStepAndData($session, 'request_reshoot', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📝 What's the reason for the reshoot?",
                        'metadata' => ['step' => 'request_reshoot'],
                    ]],
                    'suggestions' => [
                        'Weather issues',
                        'Staging changes',
                        'Quality concerns',
                        'Missing shots',
                        'Client request',
                    ],
                ];
            }
        }
        
        $shoot = Shoot::with(['client', 'photographer'])->find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that shoot.",
                    'metadata' => ['step' => 'request_reshoot'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        // Create a reshoot note
        $shoot->notes()->create([
            'content' => "Reshoot requested: {$data['reshoot_reason']}",
            'created_by' => $session->user_id,
            'type' => 'reshoot_request',
        ]);
        
        // Update shoot status
        $shoot->status = 'reshoot_needed';
        $shoot->save();
        
        $clientName = $shoot->client?->name ?? 'Unknown';
        $photographerName = $shoot->photographer?->name ?? 'Unassigned';
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "📝 **Reshoot Request Created!**\n\n" .
                    "📍 **Property**: {$shoot->address}\n" .
                    "👤 **Client**: {$clientName}\n" .
                    "📷 **Photographer**: {$photographerName}\n" .
                    "📋 **Reason**: {$data['reshoot_reason']}\n\n" .
                    "The shoot has been marked as needing a reshoot.",
                'metadata' => [
                    'step' => 'done',
                    'shoot_id' => $shoot->id,
                    'reason' => $data['reshoot_reason'],
                ],
            ]],
            'suggestions' => [
                'Schedule the reshoot',
                'Notify photographer',
                'View shoot details',
            ],
        ];
    }

    private function handleDownloadAll(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Select shoot if not selected
        if (empty($data['shoot_id'])) {
            // Get shoots with files
            $shootsWithFiles = Shoot::whereHas('files', function ($query) {
                    $query->where('status', 'completed');
                })
                ->with('client')
                ->orderBy('scheduled_at', 'desc')
                ->limit(10)
                ->get();
            
            // Try to match from message
            foreach ($shootsWithFiles as $shoot) {
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
                if ($shootsWithFiles->isEmpty()) {
                    return [
                        'assistant_messages' => [[
                            'content' => "📋 No shoots with downloadable photos found.",
                            'metadata' => ['step' => 'download_all'],
                        ]],
                        'suggestions' => [
                            'Check delivery status',
                            'Book a new shoot',
                        ],
                    ];
                }
                
                $suggestions = [];
                foreach ($shootsWithFiles as $shoot) {
                    $fileCount = $shoot->files->where('status', 'completed')->count();
                    $suggestions[] = "#{$shoot->id} - {$shoot->address} ({$fileCount} photos)";
                }
                
                $this->setStepAndData($session, 'download_all', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📥 Which shoot's photos would you like to download?",
                        'metadata' => ['step' => 'download_all'],
                    ]],
                    'suggestions' => $suggestions,
                ];
            }
        }
        
        $shoot = Shoot::with(['client', 'files'])->find($data['shoot_id']);
        
        if (!$shoot) {
            return [
                'assistant_messages' => [[
                    'content' => "❌ Could not find that shoot.",
                    'metadata' => ['step' => 'download_all'],
                ]],
                'suggestions' => ['Start over'],
            ];
        }
        
        $completedFiles = $shoot->files->where('status', 'completed');
        $fileCount = $completedFiles->count();
        
        if ($fileCount === 0) {
            return [
                'assistant_messages' => [[
                    'content' => "📋 No completed photos available for download yet.",
                    'metadata' => ['step' => 'download_all'],
                ]],
                'suggestions' => [
                    'Check delivery status',
                    'Download another shoot',
                ],
            ];
        }
        
        // Generate download link
        $downloadToken = Str::random(32);
        $downloadLink = config('app.url') . "/api/shoots/{$shoot->id}/download?token={$downloadToken}";
        
        // Store token
        $metadata = $shoot->metadata ?? [];
        $metadata['download_token'] = $downloadToken;
        $metadata['download_created_at'] = now()->toIso8601String();
        $shoot->metadata = $metadata;
        $shoot->save();
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "📥 **Download Ready!**\n\n" .
                    "📍 **Property**: {$shoot->address}\n" .
                    "📸 **Photos**: {$fileCount}\n\n" .
                    "**Download Link:**\n{$downloadLink}\n\n" .
                    "⏳ This link expires in 24 hours.",
                'metadata' => [
                    'step' => 'download_all',
                    'shoot_id' => $shoot->id,
                    'download_link' => $downloadLink,
                    'file_count' => $fileCount,
                ],
            ]],
            'suggestions' => [
                'Download another shoot',
                'Share gallery',
                'Check delivery status',
            ],
            'actions' => [
                [
                    'type' => 'download',
                    'url' => $downloadLink,
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
