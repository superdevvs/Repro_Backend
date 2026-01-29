<?php

namespace App\Console\Commands;

use App\Events\SmsMessageReceived;
use App\Events\SmsThreadUpdated;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Services\Messaging\Providers\MightyCallSmsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncMightyCallMessages extends Command
{
    protected $signature = 'mightycall:sync-messages 
                            {--hours=24 : Number of hours to look back}
                            {--number= : Specific phone number to sync}
                            {--force : Force sync even if messages exist}';

    protected $description = 'Sync SMS messages from MightyCall API to local database';

    public function __construct(
        protected MightyCallSmsProvider $provider
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $specificNumber = $this->option('number');
        $force = $this->option('force');

        $this->info("Syncing MightyCall messages from the last {$hours} hours...");

        // Get all SMS numbers or specific one
        $query = SmsNumber::query();
        if ($specificNumber) {
            $query->where('phone_number', 'LIKE', "%{$specificNumber}%");
        }
        
        $smsNumbers = $query->get();

        if ($smsNumbers->isEmpty()) {
            $this->warn('No SMS numbers configured.');
            return Command::SUCCESS;
        }

        $totalSynced = 0;
        $totalSkipped = 0;

        foreach ($smsNumbers as $smsNumber) {
            $this->info("Processing number: {$smsNumber->phone_number}");

            try {
                $result = $this->syncMessagesForNumber($smsNumber, $hours, $force);
                $totalSynced += $result['synced'];
                $totalSkipped += $result['skipped'];
                
                $this->info("  - Synced: {$result['synced']}, Skipped: {$result['skipped']}");
            } catch (\Exception $e) {
                $this->error("  - Error: {$e->getMessage()}");
                Log::error('MightyCall sync failed for number', [
                    'phone_number' => $smsNumber->phone_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sync complete. Total synced: {$totalSynced}, Total skipped: {$totalSkipped}");

        return Command::SUCCESS;
    }

    protected function syncMessagesForNumber(SmsNumber $smsNumber, int $hours, bool $force): array
    {
        $synced = 0;
        $skipped = 0;

        $startDate = now()->subHours($hours)->toIso8601String();
        $endDate = now()->toIso8601String();

        $messages = $this->provider->fetchConversations($smsNumber, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => 100,
        ]);

        if (empty($messages)) {
            $this->line("  - No messages found in MightyCall");
            return ['synced' => 0, 'skipped' => 0];
        }

        // Handle different response formats
        $messageList = $messages['messages'] ?? $messages['items'] ?? $messages;
        if (!is_array($messageList)) {
            $messageList = [];
        }

        foreach ($messageList as $mcMessage) {
            try {
                $result = $this->processMessage($mcMessage, $smsNumber, $force);
                if ($result === 'synced') {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->warn("  - Failed to process message: {$e->getMessage()}");
                $skipped++;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    protected function processMessage(array $mcMessage, SmsNumber $smsNumber, bool $force): string
    {
        // Extract message details from various possible formats
        $messageId = $mcMessage['id'] 
            ?? $mcMessage['messageId'] 
            ?? $mcMessage['guid'] 
            ?? null;

        if (!$messageId) {
            return 'skipped';
        }

        // Check if already exists
        if (!$force && Message::where('provider_message_id', $messageId)->exists()) {
            return 'skipped';
        }

        // Extract from/to
        $from = $mcMessage['from'] 
            ?? $mcMessage['From'] 
            ?? $mcMessage['caller']['phone'] 
            ?? $mcMessage['clientAddress'] 
            ?? null;
        
        $to = $mcMessage['to'] 
            ?? $mcMessage['To'] 
            ?? $mcMessage['called'][0]['phone'] 
            ?? $mcMessage['businessNumber'] 
            ?? null;

        // Handle 'to' being an array
        if (is_array($to)) {
            $to = $to[0] ?? null;
        }

        if (!$from || !$to) {
            return 'skipped';
        }

        // Determine direction
        $direction = $this->determineDirection($mcMessage, $smsNumber);
        $contactPhone = $direction === 'INBOUND' ? $from : $to;

        // Extract message text
        $messageText = $mcMessage['message'] 
            ?? $mcMessage['text'] 
            ?? $mcMessage['body'] 
            ?? '';

        // Extract timestamp
        $timestamp = $mcMessage['dateTimeUtc'] 
            ?? $mcMessage['timestamp'] 
            ?? $mcMessage['created_at'] 
            ?? now()->toIso8601String();

        // Store in database
        DB::transaction(function () use ($from, $to, $contactPhone, $messageText, $messageId, $timestamp, $direction, $mcMessage) {
            // Find or create contact
            $contact = Contact::firstOrCreate(
                ['phone' => $this->normalizePhone($contactPhone)],
                [
                    'name' => $this->formatPhoneForDisplay($contactPhone),
                    'type' => 'other',
                ]
            );

            // Find or create thread
            $thread = MessageThread::firstOrCreate(
                ['contact_id' => $contact->id, 'channel' => 'SMS'],
                ['last_message_at' => now()]
            );

            // Create or update message
            Message::updateOrCreate(
                ['provider_message_id' => $messageId],
                [
                    'channel' => 'SMS',
                    'direction' => $direction,
                    'provider' => 'MIGHTYCALL',
                    'from_address' => $from,
                    'to_address' => $to,
                    'body_text' => $messageText,
                    'status' => 'DELIVERED',
                    'sent_at' => $timestamp,
                    'thread_id' => $thread->id,
                    'metadata_json' => $mcMessage,
                ]
            );

            // Update thread
            $thread->update([
                'last_message_at' => $timestamp,
                'last_direction' => $direction,
                'last_snippet' => Str::limit($messageText, 200),
            ]);
        });

        return 'synced';
    }

    protected function determineDirection(array $mcMessage, SmsNumber $smsNumber): string
    {
        $direction = $mcMessage['direction'] 
            ?? $mcMessage['Direction'] 
            ?? $mcMessage['messageOrigin'] 
            ?? null;

        if ($direction) {
            $dirLower = strtolower($direction);
            if (in_array($dirLower, ['incoming', 'inbound'])) {
                return 'INBOUND';
            }
            if (in_array($dirLower, ['outgoing', 'outbound'])) {
                return 'OUTBOUND';
            }
        }

        // Check if 'from' matches our number
        $from = $mcMessage['from'] ?? $mcMessage['From'] ?? '';
        $ourNumber = $this->normalizePhone($smsNumber->phone_number);
        $fromNormalized = $this->normalizePhone($from);

        if ($fromNormalized === $ourNumber) {
            return 'OUTBOUND';
        }

        return 'INBOUND';
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    protected function formatPhoneForDisplay(string $phone): string
    {
        $digits = $this->normalizePhone($phone);
        
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }
        
        return $phone;
    }
}
