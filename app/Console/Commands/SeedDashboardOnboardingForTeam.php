<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\DashboardOnboardingService;
use Illuminate\Console\Command;

class SeedDashboardOnboardingForTeam extends Command
{
    protected $signature = 'onboarding:seed-team {--dry-run} {--role=*}';

    protected $description = 'Apply dashboard onboarding eligibility to existing photographer, salesRep, editing_manager, and editor users.';

    private const SEED_ROLES = ['photographer', 'salesRep', 'editing_manager', 'editor'];

    public function handle(DashboardOnboardingService $service): int
    {
        $roles = $this->option('role') ?: self::SEED_ROLES;
        $roles = array_values(array_intersect($roles, self::SEED_ROLES));

        if (empty($roles)) {
            $this->warn('No valid roles to seed.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $unchanged = 0;

        User::query()
            ->whereIn('role', $roles)
            ->chunkById(200, function ($users) use ($service, $dryRun, &$updated, &$unchanged) {
                foreach ($users as $user) {
                    $before = $user->metadata ?? [];
                    $after = $service->applyEligibility($before, $user->role, 'seed_team_command');

                    if ($after === $before) {
                        $unchanged++;
                        continue;
                    }

                    if (!$dryRun) {
                        $user->metadata = $after;
                        $user->save();
                    }
                    $updated++;
                }
            });

        $this->info(sprintf('Onboarding seed complete. Updated: %d, Unchanged: %d%s',
            $updated, $unchanged, $dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
