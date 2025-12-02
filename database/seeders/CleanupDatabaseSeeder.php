<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class CleanupDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Cleaning up database...');
        
        // Delete test/demo users (keeping only real clients and staff)
        $deletedUsers = User::where(function($query) {
            $query->where('email', 'like', '%example.com%')
                  ->orWhere('email', 'like', '%test%')
                  ->orWhere('email', 'like', '%demo%')
                  ->orWhere('name', 'like', '%Test%')
                  ->orWhere('name', 'like', '%Demo%');
        })->whereNotIn('email', [
            // Keep any specific test accounts you actually use
        ])->delete();
        
        $this->command->info("Deleted {$deletedUsers} test/demo users");
        
        // Clean up orphaned sessions
        $deletedSessions = DB::table('sessions')
            ->whereNotIn('user_id', User::pluck('id'))
            ->delete();
        
        $this->command->info("Deleted {$deletedSessions} orphaned sessions");
        
        // Clean up password reset tokens older than 24 hours
        $deletedTokens = DB::table('password_reset_tokens')
            ->where('created_at', '<', now()->subHours(24))
            ->delete();
        
        $this->command->info("Deleted {$deletedTokens} expired password reset tokens");
        
        // Vacuum the database (SQLite specific - optimize file size)
        if (config('database.default') === 'sqlite') {
            DB::statement('VACUUM');
            $this->command->info("Database vacuumed (optimized)");
        }
        
        $this->command->info('Database cleanup completed!');
    }
}

