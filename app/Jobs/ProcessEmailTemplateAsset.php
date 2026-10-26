<?php

namespace App\Jobs;

use App\Services\Messaging\EmailWorkspaceAssets;
use App\Support\LockedWrite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessEmailTemplateAsset implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public string $id) {}

    public function handle(EmailWorkspaceAssets $assets): void
    {
        $claimed = LockedWrite::run(fn () => DB::table('email_template_assets')->where('id', $this->id)->where('status', 'processing')->update(['status' => 'optimizing', 'updated_at' => now()]));
        if (! $claimed) return;
        $row = DB::table('email_template_assets')->find($this->id);
        $memoryLimit = ini_get('memory_limit'); ini_set('memory_limit', '512M');
        try { $assets->process($row); }
        catch (\Throwable $exception) { report($exception); $this->failed($exception); }
        finally { ini_set('memory_limit', $memoryLimit); }
    }

    public function failed(?\Throwable $exception): void
    {
        LockedWrite::run(fn () => DB::table('email_template_assets')->where('id', $this->id)->whereIn('status', ['processing','optimizing'])->update([
            'status' => 'failed', 'error' => 'The image could not be optimized. Your current image is unchanged. Try a smaller image.', 'updated_at' => now(),
        ]));
    }
}
