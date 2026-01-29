<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Console\Command;

class SyncCakemailContacts extends Command
{
    protected $signature = 'cakemail:sync-contacts 
                            {--list-id= : Override the default list ID}
                            {--role=client : User role to sync (client, photographer, all)}
                            {--batch-size=50 : Number of contacts to import per batch}';

    protected $description = 'Sync dashboard users to Cakemail mailing list';

    public function handle(CakemailProvider $cakemail): int
    {
        $listId = $this->option('list-id') ?? config('services.cakemail.list_id');

        if (!$listId) {
            $this->error('No list ID configured. Set CAKEMAIL_LIST_ID in .env or use --list-id option.');
            return Command::FAILURE;
        }

        $role = $this->option('role');
        $batchSize = (int) $this->option('batch-size');

        $this->info("Syncing users to Cakemail list {$listId}...");

        // Build query based on role
        $query = User::query();
        if ($role !== 'all') {
            $query->where('role', $role);
        }

        $totalUsers = $query->count();
        $this->info("Found {$totalUsers} users to sync.");

        if ($totalUsers === 0) {
            $this->warn('No users found to sync.');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $synced = 0;
        $failed = 0;

        // Process in batches
        $query->chunk($batchSize, function ($users) use ($cakemail, $listId, &$synced, &$failed, $bar) {
            $contacts = $users->map(function ($user) {
                return [
                    'email' => $user->email,
                    'attributes' => [
                        'first_name' => $user->first_name ?? $user->name ?? '',
                        'last_name' => $user->last_name ?? '',
                        'company' => $user->company_name ?? '',
                        'phone' => $user->phone ?? '',
                        'role' => $user->role ?? 'client',
                        'dashboard_user_id' => (string) $user->id,
                    ],
                    'tags' => [$user->role ?? 'client', 'dashboard-sync'],
                ];
            })->toArray();

            $result = $cakemail->importContacts((int) $listId, $contacts);

            if ($result['success']) {
                $synced += count($contacts);
            } else {
                $failed += count($contacts);
                $this->newLine();
                $this->warn("Batch failed: " . ($result['error'] ?? 'Unknown error'));
            }

            $bar->advance(count($contacts));
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Sync completed: {$synced} synced, {$failed} failed.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
