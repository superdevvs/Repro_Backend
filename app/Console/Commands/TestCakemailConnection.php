<?php

namespace App\Console\Commands;

use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Console\Command;

class TestCakemailConnection extends Command
{
    protected $signature = 'cakemail:test';

    protected $description = 'Test Cakemail API connection and retrieve account info, senders, and lists';

    public function handle(CakemailProvider $cakemail): int
    {
        $this->info('Testing Cakemail API connection...');
        $this->newLine();

        $result = $cakemail->testConnection();

        if (!$result['success']) {
            $this->error('Connection failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info('✓ Connection successful!');
        $this->newLine();

        // Display account info
        if (!empty($result['account'])) {
            $this->info('Account Information:');
            $account = $result['account'];
            $this->table(
                ['Field', 'Value'],
                [
                    ['Name', $account['name'] ?? 'N/A'],
                    ['ID', $account['id'] ?? 'N/A'],
                    ['Domain', $account['domains']['email'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // Display senders
        if (!empty($result['senders'])) {
            $this->info('Configured Senders:');
            $senderRows = array_map(function ($sender) {
                return [
                    $sender['id'] ?? 'N/A',
                    $sender['email'] ?? 'N/A',
                    $sender['name'] ?? 'N/A',
                    ($sender['confirmed'] ?? false) ? '✓ Yes' : '✗ No',
                ];
            }, $result['senders']);
            $this->table(['ID', 'Email', 'Name', 'Confirmed'], $senderRows);
            $this->newLine();

            // Show sender ID to use in .env
            $confirmedSender = collect($result['senders'])->firstWhere('confirmed', true);
            if ($confirmedSender) {
                $this->info("Recommended CAKEMAIL_SENDER_ID: {$confirmedSender['id']}");
            }
        } else {
            $this->warn('No senders configured. You need to add and verify a sender in Cakemail.');
        }

        // Display lists
        if (!empty($result['lists'])) {
            $this->newLine();
            $this->info('Mailing Lists:');
            $listRows = array_map(function ($list) {
                return [
                    $list['id'] ?? 'N/A',
                    $list['name'] ?? 'N/A',
                    $list['status'] ?? 'N/A',
                ];
            }, $result['lists']);
            $this->table(['ID', 'Name', 'Status'], $listRows);

            // Show list ID to use in .env
            $firstList = $result['lists'][0] ?? null;
            if ($firstList) {
                $this->newLine();
                $this->info("Recommended CAKEMAIL_LIST_ID: {$firstList['id']}");
            }
        } else {
            $this->warn('No mailing lists found. Create a list in Cakemail to sync contacts.');
        }

        $this->newLine();
        $this->info('Update your .env file with CAKEMAIL_SENDER_ID and CAKEMAIL_LIST_ID from above.');

        return Command::SUCCESS;
    }
}
