<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class ClearAllDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->warn('⚠️  WARNING: This will DELETE ALL accounts and shoots data!');
        
        // Delete all shoots
        $shootsCount = DB::table('shoots')->count();
        DB::table('shoots')->delete();
        $this->command->info("Deleted {$shootsCount} shoots");
        
        // Delete all users except super admins (to preserve your admin account)
        $usersCount = User::whereNotIn('role', ['super_admin', 'superadmin'])->count();
        User::whereNotIn('role', ['super_admin', 'superadmin'])->delete();
        $this->command->info("Deleted {$usersCount} users (preserved super admins)");
        
        // Vacuum database to reclaim space
        if (config('database.default') === 'sqlite') {
            DB::statement('VACUUM');
            $this->command->info("Database vacuumed and optimized");
        }
        
        $this->command->info('✅ All data cleared successfully!');
    }
}

