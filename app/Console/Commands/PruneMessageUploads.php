<?php

namespace App\Console\Commands;

use App\Models\MessageMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneMessageUploads extends Command
{
    protected $signature = 'messaging:prune-uploads';
    protected $description = 'Remove expired, unattached messaging uploads without touching sent files';
    public function handle(): int
    {
        MessageMedia::whereNull('message_id')->whereNull('send_request_key')->where('expires_at', '<', now())->chunkById(100, function ($files) {
            foreach ($files as $file) {
                app(\App\Services\Messaging\MessageMediaService::class)->locked($file, function ($fresh) {
                    if (! $fresh || $fresh->message_id || $fresh->send_request_key || $fresh->expires_at->isFuture()) { return; }
                    Storage::disk('message_media')->deleteDirectory($fresh->directory()); $fresh->delete();
                });
            }
        });
        return self::SUCCESS;
    }
}
