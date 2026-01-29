<?php

namespace App\Console\Commands;

use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Console\Command;

class SendCakemailTestEmail extends Command
{
    protected $signature = 'cakemail:send-test 
                            {to : Recipient email address}
                            {--subject=Test Email from Repro Dashboard : Email subject}
                            {--message=This is a test email sent via Cakemail API. : Email body}';

    protected $description = 'Send a test email via Cakemail API';

    public function handle(CakemailProvider $cakemail): int
    {
        $to = $this->argument('to');
        $subject = $this->option('subject');
        $message = $this->option('message');

        $this->info("Sending test email to {$to}...");

        // Build HTML content
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>R/E Pro Photos</h1>
        </div>
        <div class="content">
            <h2>{$subject}</h2>
            <p>{$message}</p>
            <p>This email was sent at: {$this->now()}</p>
        </div>
        <div class="footer">
            <p>© R/E Pro Photos - Real Estate Photography</p>
            <p>This is a test email from the Cakemail integration.</p>
        </div>
    </div>
</body>
</html>
HTML;

        try {
            // Create a dummy MessageChannel for the provider
            $channel = new \App\Models\MessageChannel([
                'display_name' => config('mail.from.name', 'R/E Pro Photos'),
                'from_email' => config('services.cakemail.username'),
                'config_json' => [
                    'cakemail_sender_id' => config('services.cakemail.sender_id'),
                    'cakemail_list_id' => config('services.cakemail.list_id'),
                ],
            ]);

            $messageId = $cakemail->send($channel, [
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => strip_tags(str_replace(['<br>', '</p>'], "\n", $message)),
                'tags' => ['test-email', 'cli'],
            ]);

            $this->info("✓ Email sent successfully!");
            $this->info("Message ID: {$messageId}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to send email: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s T');
    }
}
