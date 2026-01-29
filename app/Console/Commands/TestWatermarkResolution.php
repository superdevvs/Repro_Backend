<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestWatermarkResolution extends Command
{
    protected $signature = 'test:watermark {--shoot=53} {--user=57}';
    protected $description = 'Test watermark path resolution for a shoot as a specific user';

    public function handle(): int
    {
        $shootId = $this->option('shoot');
        $userId = $this->option('user');
        
        $shoot = Shoot::find($shootId);
        $user = User::find($userId);
        
        if (!$shoot) {
            $this->error("Shoot {$shootId} not found");
            return 1;
        }
        if (!$user) {
            $this->error("User {$userId} not found");
            return 1;
        }

        $this->info("=== WATERMARK TEST ===");
        $this->info("Shoot ID: {$shoot->id}");
        $this->info("User: {$user->name} (ID: {$user->id}, Role: {$user->role})");
        $this->info("");
        
        // Calculate needsWatermark
        $isClient = $user->role === 'client';
        $paymentStatus = $shoot->payment_status;
        $bypassPaywall = $shoot->bypass_paywall;
        $needsWatermark = $isClient && !$bypassPaywall && $paymentStatus !== 'paid';
        
        $this->info("=== WATERMARK DECISION ===");
        $this->info("isClient: " . ($isClient ? 'TRUE' : 'FALSE'));
        $this->info("bypass_paywall: " . ($bypassPaywall ? 'TRUE' : 'FALSE'));
        $this->info("payment_status: {$paymentStatus}");
        $this->info("needsWatermark: " . ($needsWatermark ? 'TRUE' : 'FALSE'));
        $this->info("");
        
        // Get first completed file
        $file = ShootFile::where('shoot_id', $shootId)
            ->where('workflow_stage', 'completed')
            ->first();
            
        if (!$file) {
            $this->error("No completed files found");
            return 1;
        }

        $this->info("=== FILE INFO ===");
        $this->info("File ID: {$file->id}");
        $this->info("Filename: {$file->filename}");
        $this->info("watermarked_web_path: " . ($file->watermarked_web_path ?? 'NULL'));
        $this->info("web_path: " . ($file->web_path ?? 'NULL'));
        $this->info("");
        
        // Resolve URLs
        $baseUrl = rtrim(config('app.url'), '/');
        
        $resolvePreviewPath = function (?string $path) use ($baseUrl) {
            if (!$path) return null;
            if (preg_match('/^https?:\/\//i', $path)) return $path;
            
            $clean = ltrim($path, '/');
            if (Str::startsWith($clean, 'storage/')) {
                $clean = substr($clean, 8);
            }
            
            if (Storage::disk('public')->exists($clean)) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));
                $url = Storage::disk('public')->url($encoded);
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $url = $baseUrl . '/' . ltrim($url, '/');
                }
                return $url;
            }
            return null;
        };
        
        $this->info("=== URL RESOLUTION ===");
        
        if ($needsWatermark) {
            $mediumUrl = $resolvePreviewPath($file->watermarked_web_path);
            $thumbUrl = $resolvePreviewPath($file->watermarked_thumbnail_path);
            $this->info("Mode: WATERMARKED");
            $this->info("medium_url: " . ($mediumUrl ?? 'NULL'));
            $this->info("thumb_url: " . ($thumbUrl ?? 'NULL'));
        } else {
            $mediumUrl = $resolvePreviewPath($file->web_path);
            $thumbUrl = $resolvePreviewPath($file->thumbnail_path);
            $this->info("Mode: NON-WATERMARKED");
            $this->info("medium_url: " . ($mediumUrl ?? 'NULL'));
            $this->info("thumb_url: " . ($thumbUrl ?? 'NULL'));
        }

        return 0;
    }
}
