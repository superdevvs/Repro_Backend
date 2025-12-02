<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\Providers\MightyCallSmsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMightyCallHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mightycall:sync-history 
                            {--number= : Specific SMS number ID to sync}
                            {--limit=100 : Maximum number of messages to fetch per number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync SMS conversation history from MightyCall API';

    public function __construct(
        private readonly MightyCallSmsProvider $mightyCallProvider
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $numberId = $this->option('number');
        $limit = (int) $this->option('limit');

        $query = SmsNumber::whereNotNull('mighty_call_key');
        
        if ($numberId) {
            $query->where('id', $numberId);
        }

        $numbers = $query->get();

        if ($numbers->isEmpty()) {
            $this->error('No MightyCall numbers configured.');
            return 1;
        }

        $this->info("Syncing history for {$numbers->count()} number(s)...");

        $totalSynced = 0;

        foreach ($numbers as $number) {
            $this->info("Syncing for number: {$number->phone_number} (ID: {$number->id})");
            
            try {
                $synced = $this->syncNumberHistory($number, $limit);
                $totalSynced += $synced;
                $this->info("  ✓ Synced {$synced} messages");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                Log::error('MightyCall sync failed', [
                    'sms_number_id' => $number->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("\nTotal messages synced: {$totalSynced}");
        return 0;
    }

    protected function syncNumberHistory(SmsNumber $number, int $limit): int
    {
        $conversations = $this->mightyCallProvider->fetchConversations($number, [
            'limit' => $limit,
        ]);

        if (empty($conversations)) {
            return 0;
        }

        // Handle different response formats
        $messages = $conversations['data'] ?? $conversations['messages'] ?? (is_array($conversations) && isset($conversations[0]) ? $conversations : []);

        if (empty($messages)) {
            return 0;
        }

        $synced = 0;

        foreach ($messages as $mcMessage) {
            try {
                $this->syncMessage($number, $mcMessage);
                $synced++;
            } catch (\Exception $e) {
                Log::warning('Failed to sync individual message', [
                    'sms_number_id' => $number->id,
                    'message' => $mcMessage,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    protected function syncMessage(SmsNumber $number, array $mcMessage): void
    {
        // Extract message data (adjust based on actual MightyCall API response format)
        $from = $mcMessage['from'] ?? $mcMessage['fromNumber'] ?? null;
        $to = $mcMessage['to'] ?? $mcMessage['toNumber'] ?? null;
        $text = $mcMessage['message'] ?? $mcMessage['text'] ?? $mcMessage['body'] ?? '';
        $direction = $this->determineDirection($number->phone_number, $from, $to);
        $timestamp = $mcMessage['timestamp'] ?? $mcMessage['createdAt'] ?? $mcMessage['date'] ?? now();
        $messageId = $mcMessage['id'] ?? $mcMessage['messageId'] ?? null;

        if (!$from || !$to || !$text) {
            return; // Skip invalid messages
        }

        // Determine contact phone (the other party)
        $contactPhone = $direction === 'OUTBOUND' ? $to : $from;

        // Find or create contact
        $contact = Contact::firstOrCreate(
            ['phone' => $contactPhone],
            ['name' => $contactPhone, 'type' => 'other']
        );

        // Find or create thread
        $thread = MessageThread::firstOrCreate(
            ['contact_id' => $contact->id, 'channel' => 'SMS'],
            ['last_message_at' => $timestamp]
        );

        // Check if message already exists
        if ($messageId) {
            $existing = Message::where('provider_message_id', (string) $messageId)
                ->where('thread_id', $thread->id)
                ->first();

            if ($existing) {
                return; // Already synced
            }
        }

        // Create message
        Message::create([
            'channel' => 'SMS',
            'direction' => $direction,
            'provider' => 'MIGHTYCALL',
            'from_address' => $from,
            'to_address' => $to,
            'body_text' => $text,
            'status' => 'SENT',
            'sent_at' => $timestamp,
            'provider_message_id' => $messageId,
            'thread_id' => $thread->id,
            'message_channel_id' => null,
        ]);

        // Update thread
        $thread->update([
            'last_message_at' => $timestamp,
            'last_direction' => $direction,
            'last_snippet' => \Illuminate\Support\Str::limit($text, 200),
        ]);
    }

    protected function determineDirection(string $numberPhone, ?string $from, ?string $to): string
    {
        $numberDigits = preg_replace('/\D/', '', $numberPhone);
        $fromDigits = $from ? preg_replace('/\D/', '', $from) : '';
        $toDigits = $to ? preg_replace('/\D/', '', $to) : '';

        // If our number is in the "from" field, it's outbound
        if (str_ends_with($fromDigits, $numberDigits) || str_ends_with($numberDigits, $fromDigits)) {
            return 'OUTBOUND';
        }

        return 'INBOUND';
    }
}
