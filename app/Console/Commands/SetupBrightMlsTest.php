<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Service;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SetupBrightMlsTest extends Command
{
    protected $signature = 'brightmls:setup-test
        {--email=brightmls.test@reprophotos.com : Client email}
        {--name=Bright MLS Tester : Client name}
        {--mls-id=TEST-MLS-1234 : MLS ID to attach to the shoot}
        {--photos=10 : Number of sample photos to create}
        {--send-email : Send the shoot ready email after setup}';

    protected $description = 'Create a Bright MLS test client, shoot, and sample photo portfolio.';

    public function handle(MailService $mailService): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $mlsId = (string) $this->option('mls-id');
        $photoCount = max(1, (int) $this->option('photos'));

        $client = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => Str::slug($name) . '-' . random_int(100, 999),
                'phonenumber' => '202555' . random_int(1000, 9999),
                'company_name' => $name . ' Realty',
                'role' => 'client',
                'account_status' => 'active',
                'password' => Hash::make('Password123!'),
            ]
        );

        $photographer = User::where('role', 'photographer')->first();
        if (!$photographer) {
            $photographer = User::create([
                'name' => 'Bright MLS Photographer',
                'username' => 'brightmls-photographer',
                'email' => 'brightmls.photographer@reprophotos.com',
                'phonenumber' => '2025550100',
                'company_name' => 'Repro Photos',
                'role' => 'photographer',
                'account_status' => 'active',
                'password' => Hash::make('Password123!'),
            ]);
        }

        $service = Service::first();
        if (!$service) {
            $service = Service::create([
                'name' => 'Bright MLS Photo Package',
                'description' => 'Sample service for Bright MLS testing',
                'category_id' => 1,
                'base_price' => 125,
                'delivery_time' => '2 business days',
            ]);
        }

        $shoot = Shoot::create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'address' => '123 Bright MLS Way',
            'city' => 'Bethesda',
            'state' => 'MD',
            'zip' => '20814',
            'mls_id' => $mlsId,
            'listing_source' => 'bright_mls',
            'scheduled_date' => now()->toDateString(),
            'time' => '10:00 AM',
            'base_quote' => 125,
            'tax_amount' => 0,
            'total_quote' => 125,
            'payment_status' => 'paid',
            'payment_type' => 'manual',
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'created_by' => $client->name,
        ]);

        $sourcePath = $this->resolveSampleImagePath();
        if (!$sourcePath || !file_exists($sourcePath)) {
            $this->error('Sample image could not be located or created.');
            return Command::FAILURE;
        }

        $photoDir = "shoots/{$shoot->id}/completed";
        Storage::disk('public')->makeDirectory($photoDir);

        for ($index = 1; $index <= $photoCount; $index++) {
            $filename = "brightmls_test_{$shoot->id}_{$index}.jpg";
            $relativePath = $photoDir . '/' . $filename;

            Storage::disk('public')->put($relativePath, file_get_contents($sourcePath));
            $fileSize = Storage::disk('public')->size($relativePath);

            ShootFile::create([
                'shoot_id' => $shoot->id,
                'filename' => $filename,
                'stored_filename' => $filename,
                'path' => $relativePath,
                'storage_path' => $relativePath,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
                'file_size' => $fileSize,
                'uploaded_by' => $photographer->id,
                'uploaded_at' => now(),
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'media_type' => 'edited',
            ]);
        }

        $shoot->edited_photo_count = $photoCount;
        $shoot->save();

        if ($this->option('send-email')) {
            $mailService->sendShootReadyEmail($client, $shoot);
        }

        $this->info('Bright MLS test setup complete.');
        $this->line("Client email: {$client->email}");
        $this->line('Client password: Password123!');
        $this->line("Shoot ID: {$shoot->id}");
        $this->line("MLS ID: {$shoot->mls_id}");
        $this->line("Sample photos: {$photoCount}");

        return Command::SUCCESS;
    }

    private function resolveSampleImagePath(): ?string
    {
        $primary = storage_path('app/public/test/image_6949d438641bb.jpg');
        if (file_exists($primary)) {
            return $primary;
        }

        $fallback = base_path('../repro-frontend/public/login-slides/slide (1).jpg');
        if (file_exists($fallback)) {
            return $fallback;
        }

        $generated = storage_path('app/public/test/brightmls_placeholder.jpg');
        if (!file_exists($generated)) {
            if (!is_dir(dirname($generated))) {
                mkdir(dirname($generated), 0755, true);
            }

            if (!function_exists('imagecreatetruecolor')) {
                return null;
            }

            $image = imagecreatetruecolor(1200, 800);
            $background = imagecolorallocate($image, 230, 233, 238);
            imagefilledrectangle($image, 0, 0, 1200, 800, $background);
            $textColor = imagecolorallocate($image, 40, 44, 52);
            imagestring($image, 5, 40, 40, 'Bright MLS Test Photo', $textColor);
            imagejpeg($image, $generated, 85);
            imagedestroy($image);
        }

        return $generated;
    }
}
