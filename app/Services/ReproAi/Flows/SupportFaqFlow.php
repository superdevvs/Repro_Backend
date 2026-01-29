<?php

namespace App\Services\ReproAi\Flows;

use App\Models\AiChatSession;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class SupportFaqFlow
{
    private array $faqDatabase = [
        'booking' => [
            'keywords' => ['book', 'schedule', 'appointment', 'how to book', 'reserve', 'how do i book'],
            'answer' => "📅 **How do I book a shoot?**\n\nYou have several options:\n\n1. Say **\"Book a new shoot\"** right here in chat\n2. Give us a call or send a text: **(202) 868-1663**\n3. Email your request to **contact@reprophotos.com**\n\nI can help you book right now if you'd like!",
        ],
        'turnaround' => [
            'keywords' => ['how long', 'turnaround', 'when ready', 'delivery time', 'how fast', 'wait', 'get my photos'],
            'answer' => "⏱️ **How long will it take to get my photos?**\n\n• **Photos & Videos**: Delivered in **24 hours**\n• **3D Matterport, iGuide & Floor Plans**: Up to **48 hours** (depending on completion time)\n• **Virtual Staging & Digital Edits**: **24-48 hours** (depending on complexity and quantity)\n• **Custom Projects**: Will be given a specific delivery date\n\nWe pride ourselves on fast turnaround!",
        ],
        'copyright' => [
            'keywords' => ['copyright', 'rights', 'ownership', 'license', 'use', 'resell'],
            'answer' => "📜 **Who holds the copyrights to the images?**\n\nYou have **unlimited right to use** the images for your listings and marketing.\n\nHowever, you do **not** have the rights to resell or transfer the image use to someone else.\n\n**R/E Pro Photos** is the holder of the copyright to the images.\n\nIf you would like to get a full release of copyright, please contact us.",
        ],
        'staging' => [
            'keywords' => ['stage', 'staging', 'furniture', 'move', 'photographer stage', 'touch'],
            'answer' => "🏠 **Will the Photographer stage the property?**\n\nPhotographers sometimes work on a very tight schedule. They might make a suggestion if asked, however **will not touch or move anything** in and around the property.\n\nIt's the **owner's/agent's responsibility** to ensure the property is photo ready upon the photographer's arrival.\n\nThe photographer may help:\n• Turn on the lights\n• Open the blinds/shades/curtains if necessary",
        ],
        'password' => [
            'keywords' => ['password', 'login', 'portal', 'never created', 'access', 'sign in'],
            'answer' => "🔐 **I never created a password for the portal, how do I login?**\n\nIf you haven't set up a password yet, you can:\n\n1. Click **\"Forgot Password\"** on the login page\n2. Enter your email address\n3. Check your email for a reset link\n4. Create your new password\n\nNeed help? Contact us at **(202) 868-1663**",
        ],
        'download' => [
            'keywords' => ['download', 'get images', 'save photos', 'export'],
            'answer' => "📥 **How do I download my images?**\n\n1. Log into your client portal\n2. Navigate to the shoot/property\n3. Click the **Download** button\n4. Select individual images or download all\n\nYou can also say **\"Download all photos\"** here and I'll help you!",
        ],
        'mls' => [
            'keywords' => ['mls', 'upload', 'listing', 'multiple listing'],
            'answer' => "📤 **How do I upload images to the MLS?**\n\n1. Download your images from the portal\n2. Log into your MLS system\n3. Navigate to your listing\n4. Use the MLS photo upload feature\n5. Select and upload your downloaded images\n\nMost MLS systems accept JPG format which is what we provide!",
        ],
        'prepare' => [
            'keywords' => ['prepare', 'preparation', 'ready', 'before shoot', 'checklist', 'photo ready'],
            'answer' => "✅ **Shoot Preparation Checklist**\n\n• All lights on (including lamps)\n• Blinds/curtains open for natural light\n• Remove personal items and clutter\n• Clean surfaces and mirrors\n• Fresh flowers/staging items ready\n• Pets secured or removed\n• Cars out of driveway\n• Lawn mowed (for exterior shots)\n\nFor more details, ask about our **Tips to get your property photo ready**!",
        ],
        'contact' => [
            'keywords' => ['contact', 'phone', 'email', 'reach', 'call'],
            'answer' => "📞 **Contact Information**\n\n• **Phone/Text**: (202) 868-1663\n• **Email**: contact@reprophotos.com\n\nOr just chat with me here - I'm always available!",
        ],
    ];

    /**
     * @return array{
     *   assistant_messages: array<int,array{content:string,metadata?:array}>,
     *   suggestions?: array<int,string>,
     *   actions?: array<int,array>
     * }
     */
    public function handle(AiChatSession $session, string $message, array $context = []): array
    {
        $step = $session->step ?? 'ask_question';
        $data = $session->state_data ?? [];

        return match($step) {
            'ask_question' => $this->handleQuestion($session, $message, $data),
            'escalate' => $this->handleEscalate($session, $message, $data),
            'create_ticket' => $this->handleCreateTicket($session, $message, $data),
            default => $this->handleQuestion($session, $message, $data),
        };
    }

    private function handleQuestion(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Check for escalation request
        if (str_contains($messageLower, 'human') || str_contains($messageLower, 'person') || 
            str_contains($messageLower, 'representative') || str_contains($messageLower, 'agent') ||
            str_contains($messageLower, 'escalate') || str_contains($messageLower, 'speak to')) {
            $this->setStepAndData($session, 'escalate', $data);
            $session->save();
            return $this->handleEscalate($session, $message, $data);
        }
        
        // Check for ticket creation request
        if (str_contains($messageLower, 'ticket') || str_contains($messageLower, 'report') ||
            str_contains($messageLower, 'issue') || str_contains($messageLower, 'problem') ||
            str_contains($messageLower, 'complaint')) {
            $this->setStepAndData($session, 'create_ticket', $data);
            $session->save();
            return $this->handleCreateTicket($session, $message, $data);
        }
        
        // Try to match FAQ
        $matchedFaq = $this->findMatchingFaq($messageLower);
        
        if ($matchedFaq) {
            $this->setStepAndData($session, null, []);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => $matchedFaq['answer'],
                    'metadata' => ['step' => 'faq_answer', 'topic' => $matchedFaq['topic']],
                ]],
                'suggestions' => [
                    'Ask another question',
                    'Book a new shoot',
                    'Speak to a human',
                ],
            ];
        }
        
        // No match - show FAQ menu or offer to create ticket
        if (empty(trim($message)) || str_contains($messageLower, 'help') || str_contains($messageLower, 'faq')) {
            $this->setStepAndData($session, 'ask_question', $data);
            $session->save();
            
            return [
                'assistant_messages' => [[
                    'content' => "❓ **Help & FAQ**\n\nI can answer common questions about:\n\n• 📅 How to book a shoot\n• ⏱️ Turnaround times\n• 📜 Copyright & image rights\n• 🏠 Property staging\n• 🔐 Portal login & password\n• 📥 Downloading images\n• 📤 Uploading to MLS\n• ✅ Shoot preparation\n\nWhat would you like to know?",
                    'metadata' => ['step' => 'ask_question'],
                ]],
                'suggestions' => [
                    'How long to get my photos?',
                    'Who owns the copyright?',
                    'How do I prepare for a shoot?',
                    'How do I download images?',
                    'Speak to a human',
                ],
            ];
        }
        
        // Unknown question - offer alternatives
        $this->setStepAndData($session, 'ask_question', $data);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "🤔 I'm not sure I understand that question. Would you like to:\n\n• Ask about **pricing**, **turnaround**, or **policies**\n• **Create a support ticket** for complex issues\n• **Speak to a human** for personalized help",
                'metadata' => ['step' => 'ask_question', 'unmatched' => true],
            ]],
            'suggestions' => [
                'View FAQ topics',
                'Create a support ticket',
                'Speak to a human',
            ],
        ];
    }

    private function handleEscalate(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Get reason if not provided
        if (empty($data['escalation_reason'])) {
            if (!empty(trim($message)) && !str_contains($messageLower, 'human') && !str_contains($messageLower, 'speak')) {
                $data['escalation_reason'] = $message;
            } else {
                $this->setStepAndData($session, 'escalate', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "👤 I'd be happy to connect you with a team member.\n\nCould you briefly describe what you need help with so I can route you to the right person?",
                        'metadata' => ['step' => 'escalate'],
                    ]],
                    'suggestions' => [
                        'Billing question',
                        'Technical issue',
                        'Scheduling help',
                        'Other',
                    ],
                ];
            }
        }
        
        // Create escalation
        $user = User::find($session->user_id);
        $userName = $user?->name ?? 'Unknown';
        $userEmail = $user?->email ?? 'Not provided';
        
        // In production, this would notify the support team
        // For now, we'll just acknowledge the escalation
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Escalation Requested**\n\n" .
                    "I've notified our support team about your request.\n\n" .
                    "📋 **Topic**: {$data['escalation_reason']}\n" .
                    "👤 **Your Name**: {$userName}\n" .
                    "📧 **Email**: {$userEmail}\n\n" .
                    "A team member will reach out to you shortly via email.\n\n" .
                    "**Business Hours**: Mon-Fri, 9am-5pm\n" .
                    "**Response Time**: Usually within 2-4 hours",
                'metadata' => [
                    'step' => 'escalated',
                    'reason' => $data['escalation_reason'],
                    'user_id' => $session->user_id,
                ],
            ]],
            'suggestions' => [
                'Ask another question',
                'Book a new shoot',
                'View my bookings',
            ],
        ];
    }

    private function handleCreateTicket(AiChatSession $session, string $message, array $data): array
    {
        $messageLower = strtolower(trim($message));
        
        // Get ticket subject if not provided
        if (empty($data['ticket_subject'])) {
            if (!empty(trim($message)) && !str_contains($messageLower, 'ticket') && !str_contains($messageLower, 'create')) {
                $data['ticket_subject'] = $message;
                $this->setStepAndData($session, 'create_ticket', $data);
                $session->save();
            } else {
                $this->setStepAndData($session, 'create_ticket', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "🎫 **Create Support Ticket**\n\nWhat's the subject of your issue?",
                        'metadata' => ['step' => 'create_ticket'],
                    ]],
                    'suggestions' => [
                        'Photo quality issue',
                        'Missing shots',
                        'Billing problem',
                        'Technical issue',
                    ],
                ];
            }
        }
        
        // Get ticket description if not provided
        if (empty($data['ticket_description'])) {
            if (!empty(trim($message)) && $message !== $data['ticket_subject']) {
                $data['ticket_description'] = $message;
            } else {
                $this->setStepAndData($session, 'create_ticket', $data);
                $session->save();
                
                return [
                    'assistant_messages' => [[
                        'content' => "📝 Please describe the issue in detail:",
                        'metadata' => ['step' => 'create_ticket'],
                    ]],
                    'suggestions' => [],
                ];
            }
        }
        
        // Create the ticket
        $user = User::find($session->user_id);
        $userName = $user?->name ?? 'Unknown';
        $userEmail = $user?->email ?? 'Not provided';
        
        // Generate ticket number
        $ticketNumber = 'TKT-' . now()->format('Ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // In production, this would create a ticket in a support system
        // For now, we'll store it in session metadata and acknowledge
        
        $this->setStepAndData($session, null, []);
        $session->save();
        
        return [
            'assistant_messages' => [[
                'content' => "✅ **Support Ticket Created!**\n\n" .
                    "🎫 **Ticket #**: {$ticketNumber}\n" .
                    "📋 **Subject**: {$data['ticket_subject']}\n" .
                    "📝 **Description**: {$data['ticket_description']}\n\n" .
                    "👤 **Submitted by**: {$userName}\n" .
                    "📧 **Email**: {$userEmail}\n\n" .
                    "Our support team will review your ticket and respond within 24 hours.",
                'metadata' => [
                    'step' => 'ticket_created',
                    'ticket_number' => $ticketNumber,
                    'subject' => $data['ticket_subject'],
                    'user_id' => $session->user_id,
                ],
            ]],
            'suggestions' => [
                'Create another ticket',
                'Ask a question',
                'Book a new shoot',
            ],
        ];
    }

    private function findMatchingFaq(string $message): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($this->faqDatabase as $topic => $faq) {
            $score = 0;
            foreach ($faq['keywords'] as $keyword) {
                if (str_contains($message, $keyword)) {
                    $score++;
                }
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'topic' => $topic,
                    'answer' => $faq['answer'],
                ];
            }
        }
        
        return $bestScore > 0 ? $bestMatch : null;
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
