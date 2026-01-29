<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\DropboxTokenService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResetForDeploySeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'p@ss321#';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->warn('⚠️  Reset for deploy: clearing data and seeding required accounts.');

        $this->clearDataTables();
        $this->cleanupLocalStorage();
        $this->cleanupDropbox();
        $this->seedRequiredAccounts();

        if (config('database.default') === 'sqlite') {
            DB::statement('VACUUM');
            $this->command->info('Database vacuumed and optimized.');
        }

        $this->command->info('✅ Reset for deploy completed.');
    }

    private function clearDataTables(): void
    {
        $tablesToClear = [
            'shoot_files',
            'shoot_media_albums',
            'shoot_notes',
            'shoot_activity_logs',
            'workflow_logs',
            'shoot_messages',
            'shoot_reschedule_requests',
            'shoot_share_links',
            'shoot_service',
            'dropbox_folders',
            'payments',
            'invoice_items',
            'invoice_shoot',
            'invoices',
            'editing_requests',
            'ai_editing_jobs',
            'ai_messages',
            'ai_chat_sessions',
            'messages',
            'message_threads',
            'message_templates',
            'message_channels',
            'sms_numbers',
            'contacts',
            'mmm_punchout_sessions',
            'shoots',
        ];

        $this->command->info('Clearing data tables...');

        Schema::disableForeignKeyConstraints();
        foreach ($tablesToClear as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();
            DB::table($table)->delete();
            $this->command->info("Cleared {$count} records from {$table}.");
        }
        Schema::enableForeignKeyConstraints();
    }

    private function cleanupLocalStorage(): void
    {
        $this->command->info('Cleaning local storage...');

        $publicDisk = Storage::disk('public');
        $this->deletePublicDirectoryIfHasFiles($publicDisk, 'shoots');
        $this->deletePublicDirectoryIfHasFiles($publicDisk, 'previews');

        $this->deleteLocalDirectoryIfHasFiles(storage_path('app/temp'));
        $this->deleteLocalLogFiles(storage_path('logs'));
    }

    private function deletePublicDirectoryIfHasFiles($disk, string $path): void
    {
        if (!$disk->exists($path)) {
            $this->command->info("Skipped public/{$path} (not found).");
            return;
        }

        $files = $disk->allFiles($path);
        if (empty($files)) {
            $this->command->info("Skipped public/{$path} (no files).");
            return;
        }

        $disk->deleteDirectory($path);
        $this->command->info("Deleted public/{$path}.");
    }

    private function deleteLocalDirectoryIfHasFiles(string $path): void
    {
        if (!File::exists($path) || !File::isDirectory($path)) {
            $this->command->info("Skipped {$path} (not found).");
            return;
        }

        $files = File::allFiles($path);
        if (empty($files)) {
            $this->command->info("Skipped {$path} (no files).");
            return;
        }

        File::deleteDirectory($path);
        $this->command->info("Deleted {$path}.");
    }

    private function deleteLocalLogFiles(string $path): void
    {
        if (!File::exists($path) || !File::isDirectory($path)) {
            $this->command->info("Skipped {$path} (not found).");
            return;
        }

        $files = File::files($path);
        if (empty($files)) {
            $this->command->info("Skipped {$path} (no log files).");
            return;
        }

        foreach ($files as $file) {
            File::delete($file->getPathname());
        }

        $this->command->info("Cleared log files in {$path}.");
    }

    private function cleanupDropbox(): void
    {
        $this->command->info('Checking Dropbox folders...');

        $paths = ['/shoots', '/Photo Editing/Archived Shoots'];
        $tokenService = new DropboxTokenService();
        $httpOptions = [
            'verify' => config('app.env') === 'production',
            'timeout' => 60,
        ];

        try {
            $token = $tokenService->getValidAccessToken();
        } catch (\Exception $e) {
            $this->command->warn('Dropbox token missing or invalid; skipping Dropbox cleanup.');
            return;
        }

        foreach ($paths as $path) {
            $listing = $this->listDropboxFolder($token, $httpOptions, $path);

            if ($listing === null) {
                $this->command->info("Dropbox path {$path} not found. Skipping.");
                continue;
            }

            $entries = $listing['entries'] ?? [];
            if (!empty($entries)) {
                $this->command->info("Dropbox path {$path} has media; skipping delete per instruction.");
                continue;
            }

            if ($this->deleteDropboxPath($token, $httpOptions, $path)) {
                $this->command->info("Deleted empty Dropbox path {$path}.");
            } else {
                $this->command->warn("Failed to delete Dropbox path {$path}.");
            }
        }
    }

    private function listDropboxFolder(string $token, array $httpOptions, string $path): ?array
    {
        try {
            $response = Http::withToken($token)
                ->withOptions($httpOptions)
                ->post('https://api.dropboxapi.com/2/files/list_folder', [
                    'path' => $path,
                    'recursive' => false,
                    'include_media_info' => false,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            $payload = $response->json();
            $summary = $payload['error_summary'] ?? '';
            if (str_contains($summary, 'path/not_found')) {
                return null;
            }

            $this->command->warn("Dropbox list failed for {$path}.");
            return null;
        } catch (\Exception $e) {
            $this->command->warn("Dropbox list exception for {$path}: {$e->getMessage()}");
            return null;
        }
    }

    private function deleteDropboxPath(string $token, array $httpOptions, string $path): bool
    {
        try {
            $response = Http::withToken($token)
                ->withOptions($httpOptions)
                ->post('https://api.dropboxapi.com/2/files/delete_v2', [
                    'path' => $path,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            $this->command->warn("Dropbox delete exception for {$path}: {$e->getMessage()}");
            return false;
        }
    }

    private function seedRequiredAccounts(): void
    {
        $this->command->info('Seeding required accounts...');

        $keepEmails = [
            'superadmin@reprophotos.com',
            'admin@reprophotos.com',
            'booking@reprophotos.com',
        ];

        $deletedUsers = User::whereNotIn('email', $keepEmails)->delete();
        $this->command->info("Deleted {$deletedUsers} non-required users.");

        $this->ensureAdminAccount('superadmin@reprophotos.com', 'Super Admin', 'superadmin');
        $this->ensureAdminAccount('admin@reprophotos.com', 'Admin', 'admin');
        $this->ensureEditorAccount();

        $accounts = [
            ['name' => 'Bill', 'email' => 'bill@reprophotos.com', 'role' => 'editing_manager'],
            ['name' => 'Shubham Prasad Soni', 'email' => 'shubhamprasadsoni@gmail.com', 'role' => 'photographer'],
            ['name' => 'Priyanshu Burman', 'email' => 'priyanshuasn24@gmail.com', 'role' => 'client'],
            ['name' => 'Jay Snap', 'email' => 'john.smith@repro.com', 'role' => 'salesRep'],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrNew(['email' => $account['email']]);
            $user->name = $account['name'];
            $user->role = $account['role'];
            $user->account_status = 'active';
            $user->password = Hash::make(self::DEFAULT_PASSWORD);

            if (!$user->exists) {
                $user->username = $this->generateUniqueUsername($account['name']);
                $user->email_verified_at = now();
            }

            $user->save();
            $this->command->info("Upserted {$account['role']} account: {$account['email']}.");
        }
    }

    private function ensureAdminAccount(string $email, string $name, string $role): void
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->role !== $role) {
                $user->role = $role;
                $user->account_status = 'active';
                $user->save();
            }
            return;
        }

        $user = new User();
        $user->name = $name;
        $user->email = $email;
        $user->role = $role;
        $user->username = $this->generateUniqueUsername($name);
        $user->password = Hash::make(self::DEFAULT_PASSWORD);
        $user->account_status = 'active';
        $user->email_verified_at = now();
        $user->save();

        $this->command->warn("{$email} was missing; created with default password.");
    }

    private function ensureEditorAccount(): void
    {
        $user = User::where('email', 'booking@reprophotos.com')->first();
        if ($user) {
            if ($user->role !== 'editor') {
                $user->role = 'editor';
                $user->account_status = 'active';
                $user->save();
            }
            return;
        }

        $user = new User();
        $user->name = 'Main Editor';
        $user->email = 'booking@reprophotos.com';
        $user->role = 'editor';
        $user->username = $this->generateUniqueUsername('editor');
        $user->password = Hash::make(self::DEFAULT_PASSWORD);
        $user->account_status = 'active';
        $user->email_verified_at = now();
        $user->save();

        $this->command->warn('booking@reprophotos.com was missing; created with default password.');
    }

    private function generateUniqueUsername(string $name): string
    {
        $base = Str::slug($name, '');
        $base = $base !== '' ? $base : 'user';
        $username = $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . $suffix;
            $suffix++;
        }

        return $username;
    }
}
